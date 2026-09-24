<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WhatsAppConversationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'state' => $this->state,
            'is_active' => $this->is_active,
            'active_section' => $this->metadata['active_section'] ?? null,
            'last_message_at' => $this->last_message_at,
            'transferred_to_human_at' => $this->transferred_to_human_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'contact' => $this->whenLoaded('contact', fn (): array => $this->contact->only(['id', 'name', 'phone_number'])),
            'last_message' => new WhatsAppMessageResource($this->whenLoaded('latestMessage')),
            'flight_request' => new WhatsAppFlightRequestResource($this->whenLoaded('flightRequest')),
        ];
    }
}
