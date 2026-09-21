<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppContact;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppFlightRequest;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class WhatsAppConversationService
{
    public function findOrCreateContact(string $phoneNumber, ?string $name = null, array $metadata = []): WhatsAppContact
    {
        return WhatsAppContact::query()->updateOrCreate(
            ['phone_number' => $phoneNumber],
            array_filter([
                'name' => $name,
                'metadata' => $metadata === [] ? null : array_filter($metadata),
            ], fn (mixed $value): bool => $value !== null),
        );
    }

    public function findOrCreateActiveConversation(WhatsAppContact $contact): WhatsAppConversation
    {
        $conversation = $contact->conversations()
            ->where(function (Builder $query): void {
                $query->where('is_active', true)->orWhere('state', 'TRANSFER_TO_HUMAN');
            })
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

    public function resetFlightRequest(WhatsAppConversation $conversation): WhatsAppFlightRequest
    {
        $flightRequest = $this->findOrCreateFlightRequest($conversation);
        $flightRequest->update([
            'origin' => null,
            'destination' => null,
            'departure_date' => null,
            'departure_time' => null,
            'passengers' => null,
            'trip_type' => null,
            'return_date' => null,
            'return_time' => null,
            'search_results' => null,
            'selected_aircraft' => null,
            'selected_aircraft_id' => null,
            'selected_provider_id' => null,
            'selected_match_id' => null,
            'quote_reference' => null,
            'backend_flight_request_id' => null,
            'accepted_quote_id' => null,
            'official_quote_payload' => null,
            'status' => 'collecting',
            'is_time_flexible' => null,
            'luggage_count' => null,
            'luggage_description' => null,
            'special_luggage' => null,
            'has_pets' => null,
            'pets_description' => null,
            'aircraft_preference' => null,
            'allow_alternate_airports' => null,
            'catering_required' => null,
            'ground_transport_required' => null,
            'wifi_required' => null,
            'other_services' => null,
            'client_name' => null,
            'client_email' => null,
            'company' => null,
            'budget' => null,
            'notes' => null,
            'legs' => null,
            'confirmed_at' => null,
        ]);

        return $flightRequest->refresh();
    }

    public function moveToState(WhatsAppConversation $conversation, string $state): void
    {
        $conversation->update([
            'state' => $state,
            'is_active' => $state !== 'CANCELLED',
            'last_message_at' => Carbon::now(),
        ]);
    }

    public function transferToHuman(WhatsAppConversation $conversation): void
    {
        $conversation->update([
            'state' => 'TRANSFER_TO_HUMAN',
            'is_active' => true,
            'metadata' => [...($conversation->metadata ?? []), 'bot_state_before_transfer' => $conversation->state === 'TRANSFER_TO_HUMAN' ? ($conversation->metadata['bot_state_before_transfer'] ?? 'START') : $conversation->state],
            'transferred_to_human_at' => now(),
            'last_message_at' => now(),
        ]);
    }

    public function returnToBot(WhatsAppConversation $conversation): void
    {
        if (! $conversation->transferred_to_human_at && $conversation->state !== 'TRANSFER_TO_HUMAN') {
            return;
        }
        $conversation->update([
            'state' => $conversation->metadata['bot_state_before_transfer'] ?? 'START',
            'is_active' => true,
            'transferred_to_human_at' => null,
        ]);
    }

    public function withContactLock(string $phoneNumber, Closure $callback): mixed
    {
        return Cache::store(config('whatsapp.lock_store'))->lock('whatsapp-contact-'.hash('sha256', $phoneNumber), 240)->block(5, $callback);
    }
}
