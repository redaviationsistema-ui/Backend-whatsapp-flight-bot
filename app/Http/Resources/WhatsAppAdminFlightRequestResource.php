<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WhatsAppAdminFlightRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = $this->official_quote_payload ?? [];

        return [
            'id' => $this->id,
            'conversation_id' => $this->whats_app_conversation_id,
            'contact' => $this->whenLoaded('conversation', fn (): ?array => $this->conversation?->contact ? [
                'id' => $this->conversation->contact->id,
                'name' => $this->conversation->contact->name,
                'phone_number' => $this->conversation->contact->phone_number,
            ] : null),
            'client_name' => $this->client_name,
            'client_email' => $this->client_email,
            'company' => $this->company,
            'origin' => $this->origin,
            'destination' => $this->destination,
            'route' => $this->routeLabel(),
            'trip_type' => $this->trip_type,
            'departure_date' => $this->departure_date?->toDateString(),
            'departure_time' => $this->departure_time,
            'return_date' => $this->return_date?->toDateString(),
            'return_time' => $this->return_time,
            'passengers' => $this->passengers,
            'selected_aircraft' => $this->selected_aircraft,
            'selected_aircraft_id' => $this->selected_aircraft_id,
            'aircraft_name' => $this->firstPayloadValue($payload, ['aircraft_name', 'aircraft.name', 'aircraft.model']) ?? $this->selected_aircraft,
            'aircraft_capacity' => $this->firstPayloadValue($payload, ['capacity', 'capacity_passengers', 'aircraft.capacity', 'aircraft.capacity_passengers']),
            'selected_provider_id' => $this->selected_provider_id,
            'selected_match_id' => $this->selected_match_id,
            'estimated_time' => $this->firstPayloadValue($payload, ['display_time', 'pricing.totals.estimated_hhmm', 'estimated_time']),
            'estimated_hours' => $this->firstPayloadValue($payload, ['display_route_hours', 'pricing.totals.estimated_hours', 'final_billable_hours']),
            'estimated_price' => $this->firstPayloadValue($payload, ['estimated_total', 'total', 'total_amount', 'pricing_breakdown.total', 'pricing.breakdown.total']),
            'currency' => $this->firstPayloadValue($payload, ['currency', 'pricing.currency']) ?? 'USD',
            'pricing_breakdown' => $this->firstPayloadValue($payload, ['pricing_breakdown', 'pricing.breakdown']),
            'status' => $this->status,
            'quote_reference' => $this->quote_reference,
            'backend_flight_request_id' => $this->backend_flight_request_id,
            'accepted_quote_id' => $this->accepted_quote_id,
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
            'budget' => $this->budget,
            'notes' => $this->notes,
            'legs' => $this->legs,
            'confirmed_at' => $this->confirmed_at,
            'official_quote_payload' => $payload,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function routeLabel(): ?string
    {
        if (! $this->origin || ! $this->destination) {
            return null;
        }

        return "{$this->origin} -> {$this->destination}";
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $keys
     */
    private function firstPayloadValue(array $payload, array $keys): mixed
    {
        foreach ($keys as $key) {
            $value = data_get($payload, $key);

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
