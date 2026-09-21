<?php

namespace App\Services\Flights;

use App\Models\WhatsAppFlightRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class FlightApiService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function searchFlights(WhatsAppFlightRequest $flightRequest): array
    {
        $response = $this->post('/api/v1/client/quotes/preview', $this->previewPayload($flightRequest));
        $options = $response['options'] ?? $response['matches'] ?? [];

        if (! is_array($options)) {
            throw new RuntimeException('Flight API preview response does not include valid options.');
        }

        return collect($options)
            ->filter(fn ($option): bool => is_array($option))
            ->map(fn (array $option): array => $this->normalizeOption($option))
            ->filter(fn (array $option): bool => (int) ($option['aircraft_id'] ?? 0) > 0)
            ->values()
            ->all();
    }

    public function checkAvailability(WhatsAppFlightRequest $flightRequest, int $aircraftId): ?array
    {
        return collect($this->searchFlights($flightRequest))
            ->first(fn (array $option): bool => (int) ($option['aircraft_id'] ?? 0) === $aircraftId);
    }

    /**
     * @return array<string, mixed>
     */
    public function createFlightRequest(WhatsAppFlightRequest $flightRequest): array
    {
        return $this->post('/api/v1/client/flight-requests', $this->flightRequestPayload($flightRequest));
    }

    /**
     * @return array<string, mixed>
     */
    public function previewPayload(WhatsAppFlightRequest $flightRequest): array
    {
        $payload = [
            'origin' => $flightRequest->origin,
            'destination' => $flightRequest->destination,
            'departure_datetime' => $this->combineDateTime($flightRequest->departure_date?->toDateString(), $flightRequest->departure_time),
            'passengers' => $flightRequest->passengers,
            'trip_type' => $this->officialTripType($flightRequest),
            'limit' => 8,
        ];

        if ($flightRequest->trip_type === 'ROUND_TRIP') {
            $payload['return_datetime'] = $this->combineDateTime($flightRequest->return_date?->toDateString(), $flightRequest->return_time);
        }

        $payload['legs'] = $this->legsPayload($flightRequest);

        return array_filter($payload, fn ($value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<string, mixed>
     */
    public function flightRequestPayload(WhatsAppFlightRequest $flightRequest): array
    {
        $payload = [
            ...$this->previewPayload($flightRequest),
            'aircraft_id' => $flightRequest->selected_aircraft_id,
            'provider_id' => $flightRequest->selected_provider_id,
            'match_id' => $flightRequest->selected_match_id,
            'currency' => $flightRequest->official_quote_payload['currency'] ?? null,
            'idempotency_key' => 'whatsapp-'.$flightRequest->getKey(),
        ];

        unset($payload['limit']);

        return array_filter($payload, fn ($value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function legsPayload(WhatsAppFlightRequest $flightRequest): array
    {
        $departure = $this->combineDateTime($flightRequest->departure_date?->toDateString(), $flightRequest->departure_time);
        $legs = [[
            'leg_order' => 1,
            'origin' => $flightRequest->origin,
            'destination' => $flightRequest->destination,
            'departure_datetime' => $departure,
            'passengers' => $flightRequest->passengers,
        ]];

        if ($flightRequest->trip_type === 'ROUND_TRIP') {
            $legs[] = [
                'leg_order' => 2,
                'origin' => $flightRequest->destination,
                'destination' => $flightRequest->origin,
                'departure_datetime' => $this->combineDateTime($flightRequest->return_date?->toDateString(), $flightRequest->return_time),
                'passengers' => $flightRequest->passengers,
            ];
        }

        if ($flightRequest->trip_type === 'MULTI_CITY') {
            foreach ($flightRequest->legs ?? [] as $index => $leg) {
                $legs[] = [
                    'leg_order' => $index + 2,
                    'origin' => $leg['origin'],
                    'destination' => $leg['destination'],
                    'departure_datetime' => $this->combineDateTime($leg['departure_date'], $leg['departure_time']),
                    'passengers' => $flightRequest->passengers,
                ];
            }
        }

        return array_values(array_filter($legs, fn (array $leg): bool => filled($leg['departure_datetime'] ?? null)));
    }

    private function officialTripType(WhatsAppFlightRequest $flightRequest): string
    {
        return match ($flightRequest->trip_type) {
            'ROUND_TRIP' => 'round_trip',
            'MULTI_CITY' => 'multi_city',
            default => 'one_way',
        };
    }

    private function combineDateTime(?string $date, ?string $time): ?string
    {
        if (! $date || ! $time) {
            return null;
        }

        return $date.'T'.Str::before($time, '.');
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeOption(array $option): array
    {
        $aircraft = is_array($option['aircraft'] ?? null) ? $option['aircraft'] : [];
        $pricing = is_array($option['pricing'] ?? null) ? $option['pricing'] : [];

        return [
            'aircraft_id' => (int) ($option['aircraft_id'] ?? $aircraft['id'] ?? 0),
            'provider_id' => isset($option['provider_id']) ? (int) $option['provider_id'] : null,
            'match_id' => (string) ($option['match_id'] ?? $option['id'] ?? 'preview-'.($option['aircraft_id'] ?? $aircraft['id'] ?? '')),
            'aircraft_name' => $option['aircraft_name'] ?? $option['aircraft'] ?? $option['name'] ?? $aircraft['model'] ?? $aircraft['name'] ?? 'Aeronave disponible',
            'capacity' => $option['capacity'] ?? $option['aircraft_capacity'] ?? $aircraft['capacity'] ?? null,
            'availability' => $option['availability'] ?? $option['availability_status'] ?? 'available',
            'display_route_hours' => $option['display_route_hours'] ?? $pricing['display_route_hours'] ?? null,
            'display_time' => $option['display_time'] ?? $option['time'] ?? $pricing['estimated_flight_time'] ?? null,
            'final_billable_hours' => $option['final_billable_hours'] ?? $pricing['final_billable_hours'] ?? null,
            'pricing' => $pricing,
            'total' => $option['total_amount'] ?? $option['total'] ?? $option['quoted_total'] ?? $pricing['total_amount'] ?? null,
            'currency' => $option['currency'] ?? $pricing['currency'] ?? null,
            'raw' => $option,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload): array
    {
        $baseUrl = rtrim((string) config('flight_api.base_url'), '/');

        if ($baseUrl === '') {
            throw new RuntimeException('Flight API base URL is not configured.');
        }

        try {
            $response = $this->http()
                ->post($baseUrl.$path, $payload)
                ->throw();
        } catch (ConnectionException $exception) {
            Log::error('Flight API connection failed.', [
                'path' => $path,
                'error' => $exception->getMessage(),
            ]);

            throw new RuntimeException('Flight API is not reachable.', previous: $exception);
        } catch (RequestException $exception) {
            Log::warning('Flight API request failed.', [
                'path' => $path,
                'status' => $exception->response->status(),
                'response' => $exception->response->json(),
            ]);

            throw new RuntimeException('Flight API request failed with status '.$exception->response->status(), previous: $exception);
        }

        $json = $response->json();

        if (! is_array($json)) {
            Log::error('Flight API returned invalid JSON.', [
                'path' => $path,
                'status' => $response->status(),
            ]);

            throw new RuntimeException('Flight API returned invalid JSON.');
        }

        if (($json['success'] ?? true) === false) {
            throw new RuntimeException((string) ($json['message'] ?? 'Flight API returned an unsuccessful response.'));
        }

        return $json;
    }

    private function http(): PendingRequest
    {
        $request = Http::acceptJson()
            ->asJson()
            ->connectTimeout((int) config('flight_api.connect_timeout', 5))
            ->timeout((int) config('flight_api.timeout', 20))
            ->retry(
                (int) config('flight_api.retry_times', 2),
                (int) config('flight_api.retry_sleep_ms', 250),
                throw: false,
            );

        $token = config('flight_api.token');

        if ($token) {
            $request = $request->withToken((string) $token);
        }

        return $request;
    }
}
