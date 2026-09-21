<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WhatsAppFlightRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'origin' => $this->origin,
            'destination' => $this->destination,
            'departure_date' => $this->departure_date?->toDateString(),
            'departure_time' => $this->departure_time,
            'passengers' => $this->passengers,
            'trip_type' => $this->trip_type,
            'return_date' => $this->return_date?->toDateString(),
            'return_time' => $this->return_time,
            'selected_aircraft' => $this->selected_aircraft,
            'selected_aircraft_id' => $this->selected_aircraft_id,
            'selected_provider_id' => $this->selected_provider_id,
            'selected_match_id' => $this->selected_match_id,
            'quote_reference' => $this->quote_reference,
            'backend_flight_request_id' => $this->backend_flight_request_id,
            'accepted_quote_id' => $this->accepted_quote_id,
            'status' => $this->status,
            'is_time_flexible' => $this->is_time_flexible,
            'luggage_count' => $this->luggage_count,
            'luggage_description' => $this->luggage_description,
            'special_luggage' => $this->special_luggage,
            'has_pets' => $this->has_pets,
            'pets_description' => $this->pets_description,
            'aircraft_preference' => $this->aircraft_preference,
            'allow_alternate_airports' => $this->allow_alternate_airports,
            'catering_required' => $this->catering_required,
            'ground_transport_required' => $this->ground_transport_required,
            'wifi_required' => $this->wifi_required,
            'other_services' => $this->other_services,
            'client_name' => $this->client_name,
            'client_email' => $this->client_email,
            'company' => $this->company,
            'budget' => $this->budget,
            'notes' => $this->notes,
            'legs' => $this->legs,
            'confirmed_at' => $this->confirmed_at,
        ];
    }
}
