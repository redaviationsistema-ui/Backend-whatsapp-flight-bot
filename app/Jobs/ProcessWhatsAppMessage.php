<?php

namespace App\Jobs;

use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\WhatsAppChatbotService;
use App\Services\WhatsApp\WhatsAppConversationService;
use App\Services\WhatsApp\WhatsAppMessageService;
use App\Services\WhatsApp\WhatsAppSendRejectedException;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ProcessWhatsAppMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 180;

    /** @var array<int, int> */
    public array $backoff = [5, 15, 30, 60];

    /** @param array<string, mixed> $payload */
    public function __construct(public array $payload) {}

    public function handle(WhatsAppConversationService $conversationService, WhatsAppMessageService $messageService, WhatsAppChatbotService $chatbotService, WhatsAppService $whatsAppService): void
    {
        foreach ($this->payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                foreach ($change['value']['statuses'] ?? [] as $status) {
                    $messageService->recordStatus($status);
                }
            }
        }
        foreach ($this->messagePayloads() as $payload) {
            $message = $payload['message'];
            $messageId = (string) ($message['id'] ?? '');
            $from = (string) ($message['from'] ?? '');
            if ($messageId === '' || $from === '') {
                continue;
            }
            $conversationService->withContactLock($from, function () use ($payload, $message, $messageId, $from, $conversationService, $messageService, $chatbotService, $whatsAppService): void {
                $inbound = WhatsAppMessage::query()->where('message_id', $messageId)->first();
                if ($inbound?->processed_at) {
                    $this->logAction($inbound, 'skip_processed');

                    return;
                }
                if (! $inbound) {
                    $inbound = DB::transaction(function () use ($payload, $message, $messageId, $from, $conversationService, $messageService): WhatsAppMessage {
                        $contact = $conversationService->findOrCreateContact($from, data_get($payload, 'contact.profile.name'), ['wa_id' => $from]);
                        $conversation = $conversationService->findOrCreateActiveConversation($contact);
                        $conversation->update(['last_message_at' => now(), 'is_active' => true]);

                        return $messageService->storeInboundMessage($conversation, $messageId, $message['type'] ?? 'unknown', $this->extractText($message), $message, isset($message['timestamp']) ? (int) $message['timestamp'] : null);
                    });
                }
                $this->processInbound($inbound, $conversationService, $messageService, $chatbotService, $whatsAppService);
            });
        }
    }

    private function processInbound(WhatsAppMessage $inbound, WhatsAppConversationService $conversationService, WhatsAppMessageService $messageService, WhatsAppChatbotService $chatbotService, WhatsAppService $whatsAppService): void
    {
        $inbound->refresh();
        if ($inbound->processed_at) {
            $this->logAction($inbound, 'skip_processed');

            return;
        }
        $conversation = $inbound->conversation()->firstOrFail();
        $inbound->setRelation('conversation', $conversation);
        if (($conversation->transferred_to_human_at || $conversation->state === 'TRANSFER_TO_HUMAN') && ! isset($inbound->processing_context['pending_response'])) {
            $inbound->update(['processed_at' => now(), 'processing_context' => null]);
            $this->logAction($inbound, 'skip_human_mode');

            return;
        }
        $context = $inbound->processing_context ?? [];
        if (! ($context['input_applied'] ?? false)) {
            DB::transaction(function () use ($inbound, $conversation, $conversationService, $chatbotService): void {
                $stateBefore = $conversation->state;
                $this->logAction($inbound, 'apply_input');
                $flightRequest = $conversationService->findOrCreateFlightRequest($conversation);
                $result = $inbound->body === null
                    ? ['state' => $stateBefore, 'message' => 'Por favor responde con texto para continuar.']
                    : $chatbotService->handleIncomingMessage($conversation, $flightRequest, $inbound->body);
                if ($result['state'] === 'TRANSFER_TO_HUMAN') {
                    $conversationService->transferToHuman($conversation);
                } else {
                    $conversationService->moveToState($conversation, $result['state']);
                }
                $inbound->update(['processing_context' => [
                    'input_applied' => true,
                    'state_before' => $stateBefore,
                    'pending_response' => $result['message'],
                    'pending_state' => $result['state'],
                ]]);
            });
        }
        $body = $inbound->processing_context['pending_response'] ?? '';
        if ($body !== '') {
            $response = $this->sendPendingReply($inbound, $whatsAppService);
            DB::transaction(function () use ($messageService, $conversation, $body, $response, $inbound): void {
                $messageService->storeOutboundMessage($conversation, $body, $response);
                $inbound->update(['processing_context' => ['input_applied' => true]]);
            });
            $this->logAction($inbound, 'reply_recorded');
        }
        $inbound->update(['processed_at' => now(), 'processing_context' => null]);
        $this->logAction($inbound, 'processed');
    }

    /** @return array<string, mixed> */
    private function sendPendingReply(WhatsAppMessage $inbound, WhatsAppService $whatsAppService): array
    {
        $context = $inbound->processing_context;
        if (isset($context['sent_response'])) {
            $this->logAction($inbound, 'resume_accepted_reply');

            return $context['sent_response'];
        }
        if ($context['send_started'] ?? false) {
            $this->logAction($inbound, 'send_uncertain');

            throw new RuntimeException('WhatsApp reply requires reconciliation before retrying an uncertain send.');
        }

        $inbound->update(['processing_context' => [...$context, 'send_started' => true]]);
        $this->logAction($inbound, 'send_reply');

        try {
            $response = $whatsAppService->sendTextMessage($inbound->conversation->contact->phone_number, $context['pending_response']);
        } catch (WhatsAppSendRejectedException $exception) {
            $inbound->update(['processing_context' => $context]);
            $this->logAction($inbound, 'send_rejected');

            throw $exception;
        } catch (Throwable $exception) {
            $this->logAction($inbound, 'send_uncertain');

            throw $exception;
        }

        $inbound->update(['processing_context' => [...$context, 'send_started' => true, 'sent_response' => $response]]);
        $this->logAction($inbound, 'send_accepted');

        return $response;
    }

    private function logAction(WhatsAppMessage $inbound, string $action): void
    {
        Log::info('WhatsApp inbound processing.', [
            'incoming_message_id' => $inbound->message_id,
            'conversation_id' => $inbound->whats_app_conversation_id,
            'state' => $inbound->processing_context['pending_state'] ?? $inbound->conversation->state,
            'action' => $action,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('WhatsApp message processing exhausted retries.', ['exception_type' => $exception ? $exception::class : null]);
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
                        'waba_id' => $entry['id'] ?? null,
                    ];
                }
            }
        }

        return $messages;
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
}
