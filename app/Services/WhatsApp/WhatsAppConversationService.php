<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppContact;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppFlightRequest;
use Illuminate\Support\Carbon;

class WhatsAppConversationService
{
    public function findOrCreateContact(string $phoneNumber, ?string $name = null, array $metadata = []): WhatsAppContact
    {
        return WhatsAppContact::query()->updateOrCreate(
            ['phone_number' => $phoneNumber],
            [
                'name' => $name,
                'metadata' => array_filter($metadata),
            ],
        );
    }

    public function findOrCreateActiveConversation(WhatsAppContact $contact): WhatsAppConversation
    {
        $conversation = $contact->conversations()
            ->where('is_active', true)
            ->latest()
            ->first();

        if ($conversation) {
            return $conversation;
        }

        return $contact->conversations()->create([
            'state' => 'START',
            'is_active' => true,
            'last_message_at' => now(),
        ]);
    }

    public function findOrCreateFlightRequest(WhatsAppConversation $conversation): WhatsAppFlightRequest
    {
        return $conversation->flightRequest()->firstOrCreate([
            'whats_app_conversation_id' => $conversation->id,
        ]);
    }

    public function moveToState(WhatsAppConversation $conversation, string $state): void
    {
        $conversation->update([
            'state' => $state,
            'last_message_at' => Carbon::now(),
        ]);
    }

    public function transferToHuman(WhatsAppConversation $conversation): void
    {
        $conversation->update([
            'state' => 'TRANSFER_TO_HUMAN',
            'is_active' => false,
            'transferred_to_human_at' => now(),
            'last_message_at' => now(),
        ]);
    }
}
