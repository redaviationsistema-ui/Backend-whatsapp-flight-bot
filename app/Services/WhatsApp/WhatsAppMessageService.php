<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use Illuminate\Support\Carbon;

class WhatsAppMessageService
{
    public function messageExists(string $messageId): bool
    {
        return WhatsAppMessage::query()
            ->where('message_id', $messageId)
            ->exists();
    }

    public function storeInboundMessage(
        WhatsAppConversation $conversation,
        string $messageId,
        string $type,
        ?string $body,
        array $payload,
        ?int $timestamp = null,
    ): WhatsAppMessage {
        return $conversation->messages()->create([
            'message_id' => $messageId,
            'direction' => 'inbound',
            'type' => $type,
            'body' => $body,
            'payload' => $payload,
            'sent_at' => $timestamp ? Carbon::createFromTimestamp($timestamp) : now(),
        ]);
    }

    public function storeOutboundMessage(WhatsAppConversation $conversation, string $body): WhatsAppMessage
    {
        return $conversation->messages()->create([
            'message_id' => null,
            'direction' => 'outbound',
            'type' => 'text',
            'body' => $body,
            'payload' => null,
            'sent_at' => now(),
        ]);
    }
}
