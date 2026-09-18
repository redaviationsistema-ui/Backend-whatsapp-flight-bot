<?php

namespace App\Jobs;

use App\Models\WhatsAppConversation;
use App\Services\WhatsApp\WhatsAppChatbotService;
use App\Services\WhatsApp\WhatsAppConversationService;
use App\Services\WhatsApp\WhatsAppMessageService;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessWhatsAppMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public array $payload) {}

    public function handle(
        WhatsAppConversationService $conversationService,
        WhatsAppMessageService $messageService,
        WhatsAppChatbotService $chatbotService,
        WhatsAppService $whatsAppService,
    ): void {
        try {
            $message = $this->payload['message'] ?? [];
            $messageId = (string) ($message['id'] ?? '');
            $from = (string) ($message['from'] ?? '');

            if ($messageId === '' || $from === '') {
                Log::warning('WhatsApp inbound message skipped because identifiers are missing.', [
                    'payload' => $this->payload,
                ]);

                return;
            }

            if ($messageService->messageExists($messageId)) {
                Log::info('WhatsApp inbound message skipped because it was already processed.', [
                    'message_id' => $messageId,
                ]);

                return;
            }

            $conversation = DB::transaction(function () use ($conversationService, $messageService, $message, $messageId, $from): WhatsAppConversation {
                $contactPayload = $this->payload['contact'] ?? [];
                $profile = $contactPayload['profile'] ?? [];

                $contact = $conversationService->findOrCreateContact(
                    $from,
                    $profile['name'] ?? null,
                    ['wa_id' => $contactPayload['wa_id'] ?? $from],
                );

                $conversation = $conversationService->findOrCreateActiveConversation($contact);

                $messageService->storeInboundMessage(
                    $conversation,
                    $messageId,
                    (string) ($message['type'] ?? 'unknown'),
                    $this->extractText($message),
                    $message,
                    isset($message['timestamp']) ? (int) $message['timestamp'] : null,
                );

                return $conversation->refresh();
            });

            $flightRequest = $conversationService->findOrCreateFlightRequest($conversation);
            $result = $chatbotService->handleIncomingMessage($conversation, $flightRequest, $this->extractText($message) ?? '');

            if ($result['state'] === 'TRANSFER_TO_HUMAN') {
                $conversationService->transferToHuman($conversation);
            } else {
                $conversationService->moveToState($conversation, $result['state']);
            }

            $messageService->storeOutboundMessage($conversation, $result['message']);
            $whatsAppService->sendTextMessage($from, $result['message']);

            $this->continueAutomatedFlow($conversation->refresh(), $from, $conversationService, $messageService, $chatbotService, $whatsAppService);
        } catch (Throwable $throwable) {
            Log::error('WhatsApp inbound message processing failed.', [
                'error' => $throwable->getMessage(),
                'payload' => $this->payload,
            ]);

            throw $throwable;
        }
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function extractText(array $message): ?string
    {
        if (($message['type'] ?? null) === 'text') {
            return $message['text']['body'] ?? null;
        }

        if (isset($message['button']['text'])) {
            return $message['button']['text'];
        }

        if (isset($message['interactive']['button_reply']['title'])) {
            return $message['interactive']['button_reply']['id']
                ?? $message['interactive']['button_reply']['title'];
        }

        if (isset($message['interactive']['list_reply']['title'])) {
            return $message['interactive']['list_reply']['id']
                ?? $message['interactive']['list_reply']['title'];
        }

        return null;
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('WhatsApp message processing failed.', [
            'error' => $exception?->getMessage(),
            'payload' => $this->payload,
        ]);
    }

    private function continueAutomatedFlow(
        WhatsAppConversation $conversation,
        string $to,
        WhatsAppConversationService $conversationService,
        WhatsAppMessageService $messageService,
        WhatsAppChatbotService $chatbotService,
        WhatsAppService $whatsAppService,
    ): void {
        while (in_array($conversation->state, ['SEARCH_FLIGHTS', 'SHOW_RESULTS', 'CREATE_QUOTE'], true)) {
            $flightRequest = $conversationService->findOrCreateFlightRequest($conversation);
            $result = $chatbotService->continueAutomatedState($conversation, $flightRequest);

            if ($result['message'] === '') {
                return;
            }

            if ($result['state'] === 'TRANSFER_TO_HUMAN') {
                $conversationService->transferToHuman($conversation);
            } else {
                $conversationService->moveToState($conversation, $result['state']);
            }

            $messageService->storeOutboundMessage($conversation, $result['message']);
            $whatsAppService->sendTextMessage($to, $result['message']);
            $conversation = $conversation->refresh();
        }
    }
}
