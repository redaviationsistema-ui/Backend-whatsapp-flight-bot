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

    public int $timeout = 60;

    public function __construct(public array $payload) {}

    public function handle(
        WhatsAppConversationService $conversationService,
        WhatsAppMessageService $messageService,
        WhatsAppChatbotService $chatbotService,
        WhatsAppService $whatsAppService,
    ): void {
        try {
            foreach ($this->messagePayloads() as $messagePayload) {
                $this->processMessagePayload(
                    $messagePayload,
                    $conversationService,
                    $messageService,
                    $chatbotService,
                    $whatsAppService,
                );
            }
        } catch (Throwable $throwable) {
            Log::error('WhatsApp inbound message processing failed.', [
                'error' => $throwable->getMessage(),
                'payload' => $this->payload,
            ]);

            throw $throwable;
        }
    }

    /**
     * @return array<int, array{message:array<string, mixed>, contact:array<string, mixed>, metadata:array<string, mixed>}>
     */
    private function messagePayloads(): array
    {
        if (isset($this->payload['message'])) {
            return [[
                'message' => $this->payload['message'],
                'contact' => $this->payload['contact'] ?? [],
                'metadata' => $this->payload['metadata'] ?? [],
            ]];
        }

        $messages = [];

        foreach ($this->payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                $contacts = collect($value['contacts'] ?? [])->keyBy('wa_id');

                foreach ($value['messages'] ?? [] as $message) {
                    $from = $message['from'] ?? null;

                    if (! $from || ! isset($message['id'])) {
                        continue;
                    }

                    $messages[] = [
                        'message' => $message,
                        'contact' => $contacts->get($from, []),
                        'metadata' => $value['metadata'] ?? [],
                    ];
                }
            }
        }

        return $messages;
    }

    /**
     * @param  array{message:array<string, mixed>, contact:array<string, mixed>, metadata:array<string, mixed>}  $messagePayload
     */
    private function processMessagePayload(
        array $messagePayload,
        WhatsAppConversationService $conversationService,
        WhatsAppMessageService $messageService,
        WhatsAppChatbotService $chatbotService,
        WhatsAppService $whatsAppService,
    ): void {
        $message = $messagePayload['message'];
        $messageId = (string) ($message['id'] ?? '');
        $from = (string) ($message['from'] ?? '');

        if ($messageId === '' || $from === '') {
            Log::warning('WhatsApp inbound message skipped because identifiers are missing.', [
                'payload' => $messagePayload,
            ]);

            return;
        }

        if ($messageService->messageExists($messageId)) {
            Log::info('WhatsApp inbound message skipped because it was already processed.', [
                'message_id' => $messageId,
            ]);

            return;
        }

        $conversation = DB::transaction(function () use ($conversationService, $messageService, $messagePayload, $message, $messageId, $from): WhatsAppConversation {
            $contactPayload = $messagePayload['contact'];
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
