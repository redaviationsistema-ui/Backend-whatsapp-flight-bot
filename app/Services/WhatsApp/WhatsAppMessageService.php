<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WhatsAppMessageService
{
    public function messageExists(string $messageId): bool
    {
        return WhatsAppMessage::query()->where('message_id', $messageId)->exists();
    }

    /** @param array<string, mixed> $payload */
    public function storeInboundMessage(WhatsAppConversation $conversation, string $messageId, string $type, ?string $body, array $payload, ?int $timestamp = null): WhatsAppMessage
    {
        return $conversation->messages()->firstOrCreate(['message_id' => $messageId], [
            'direction' => 'inbound',
            'type' => $type,
            'body' => $body,
            'payload' => $payload,
            'sent_at' => $timestamp ? Carbon::createFromTimestamp($timestamp) : now(),
        ]);
    }

    /** @param array<string, mixed> $response */
    public function storeOutboundMessage(WhatsAppConversation $conversation, string $body, array $response): WhatsAppMessage
    {
        $messageId = data_get($response, 'messages.0.id');
        if (! is_string($messageId) || $messageId === '') {
            throw new RuntimeException('Meta did not return a message ID.');
        }
        $message = $conversation->messages()->firstOrCreate(['message_id' => $messageId], [
            'direction' => 'outbound',
            'type' => 'text',
            'body' => $body,
            'sent_at' => now(),
            'status' => 'sent',
        ]);
        $conversation->update(['last_message_at' => now()]);
        $this->applyStatuses($messageId);
        DB::afterCommit(fn () => $this->applyStatuses($messageId));

        return $message->refresh();
    }

    /** @param array<string, mixed> $event */
    public function recordStatus(array $event): void
    {
        $status = $event['status'] ?? null;
        $messageId = $event['id'] ?? null;
        if (! is_string($messageId) || $messageId === '' || ! in_array($status, ['sent', 'delivered', 'read', 'failed'], true) || ! is_numeric($event['timestamp'] ?? null)) {
            return;
        }
        DB::table('whats_app_message_statuses')->insertOrIgnore([
            'message_id' => $messageId,
            'status' => $status,
            'occurred_at' => Carbon::createFromTimestamp((int) $event['timestamp'])->utc(),
            'error_code' => isset($event['errors'][0]['code']) ? (string) $event['errors'][0]['code'] : null,
            'error_message' => $event['errors'][0]['title'] ?? null,
        ]);
        $this->applyStatuses($messageId);
    }

    private function applyStatuses(string $messageId): void
    {
        DB::transaction(function () use ($messageId): void {
            $message = WhatsAppMessage::query()->where('message_id', $messageId)->where('direction', 'outbound')->lockForUpdate()->first();
            if (! $message) {
                return;
            }
            $events = DB::table('whats_app_message_statuses')->where('message_id', $messageId)->get()->keyBy('status');
            foreach (['delivered', 'read', 'failed'] as $status) {
                if (isset($events[$status])) {
                    $message->{$status === 'delivered' ? 'delivered_at' : $status.'_at'} = $events[$status]->occurred_at;
                }
            }
            if (isset($events['failed'])) {
                $message->error_code = $events['failed']->error_code;
                $message->error_message = $events['failed']->error_message;
            }
            $message->status = $message->read_at ? 'read' : ($message->delivered_at ? 'delivered' : ($message->failed_at ? 'failed' : 'sent'));
            $message->save();
        });
    }
}
