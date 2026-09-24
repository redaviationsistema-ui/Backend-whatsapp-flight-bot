<?php

namespace App\Services\Flights;

use App\Models\WhatsAppFlightRequest;
use Illuminate\Support\Str;
use RuntimeException;

class FlightRequestService
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function create(WhatsAppFlightRequest $flightRequest, array $payload): array
    {
        $aircraftId = (string) ($payload['aircraft_id'] ?? '');

        if ($aircraftId === '' || ! Str::isUuid($aircraftId)) {
            throw new RuntimeException('Invalid aircraft UUID.');
        }

        $officialQuotePayload = array_filter([
            ...($flightRequest->official_quote_payload ?? []),
            ...$payload,
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        $acceptedQuoteId = $flightRequest->accepted_quote_id ?? $flightRequest->getKey();

        $flightRequest->update([
            'selected_aircraft_id' => $aircraftId,
            'selected_provider_id' => $payload['provider_id'] ?? null,
            'selected_match_id' => $payload['match_id'] ?? null,
            'official_quote_payload' => $officialQuotePayload,
            'backend_flight_request_id' => $flightRequest->getKey(),
            'accepted_quote_id' => $acceptedQuoteId,
            'quote_reference' => 'QUOTE-'.$acceptedQuoteId,
            'status' => 'quoted',
        ]);

        return [
            'success' => true,
            'flight_request' => [
                'id' => $flightRequest->getKey(),
            ],
            'accepted_quote' => [
                'id' => $acceptedQuoteId,
                'aircraft_id' => $aircraftId,
                'provider_id' => $payload['provider_id'] ?? null,
                'match_id' => $payload['match_id'] ?? null,
                'total' => data_get($officialQuotePayload, 'total'),
                'currency' => data_get($officialQuotePayload, 'currency'),
            ],
        ];
    }
}
