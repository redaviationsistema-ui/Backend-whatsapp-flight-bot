<?php

namespace Tests\Feature;

use App\Models\WhatsAppContact;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppFlightRequest;
use App\Services\Flights\FlightApiService;
use App\Services\Quotes\QuoteEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class FlightApiServiceTest extends TestCase
{
    use RefreshDatabase;

    private const AIRCRAFT_UUID = '16450000-0000-4000-8000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'flight_api.mode' => 'remote',
            'flight_api.base_url' => 'https://backend.test',
            'flight_api.token' => 'plain-api-token',
            'flight_api.retry_times' => 0,
        ]);
    }

    public function test_local_mode_uses_quote_engine_without_self_http_preview_request(): void
    {
        config(['flight_api.mode' => 'local']);
        Http::preventStrayRequests();
        $this->mock(QuoteEngine::class, function ($mock): void {
            $mock->shouldReceive('preview')
                ->once()
                ->with(Mockery::on(fn (array $payload): bool => ($payload['origin'] ?? null) === 'Toluca'
                    && ($payload['destination'] ?? null) === 'Cancun'
                    && ($payload['passengers'] ?? null) === 5))
                ->andReturn([
                    'status' => 'ok',
                    'options' => [[
                        'aircraft_id' => self::AIRCRAFT_UUID,
                        'aircraft_name' => 'Citation CJ3',
                        'total' => 55000,
                    ]],
                ]);
        });

        $options = app(FlightApiService::class)->searchFlights($this->flightRequest());

        $this->assertSame(self::AIRCRAFT_UUID, $options[0]['aircraft_id']);
        Http::assertNothingSent();
    }

    public function test_it_normalizes_preview_options_from_official_backend(): void
    {
        Http::fake([
            'https://backend.test/api/v1/client/quotes/preview' => Http::response([
                'success' => true,
                'options' => [[
                    'aircraft_id' => self::AIRCRAFT_UUID,
                    'provider_id' => 201,
                    'match_id' => 'match-101',
                    'aircraft_name' => 'Gulfstream G-IV',
                    'capacity' => 12,
                    'display_time' => '3:51 hrs',
                    'total_amount' => 55000,
                    'currency' => 'USD',
                ]],
            ]),
        ]);

        $options = app(FlightApiService::class)->searchFlights($this->flightRequest());

        $this->assertCount(1, $options);
        $this->assertSame(self::AIRCRAFT_UUID, $options[0]['aircraft_id']);
        $this->assertSame(201, $options[0]['provider_id']);
        $this->assertSame('match-101', $options[0]['match_id']);
        $this->assertSame('Gulfstream G-IV', $options[0]['aircraft_name']);
        $this->assertSame(55000, $options[0]['total']);
    }

    public function test_missing_currency_is_not_invented_in_options_or_quote_payload(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://backend.test/api/v1/client/quotes/preview' => Http::response([
                'options' => [['aircraft_id' => self::AIRCRAFT_UUID, 'total' => 55000]],
            ]),
        ]);
        $flight = $this->flightRequest();
        $service = app(FlightApiService::class);

        $options = $service->searchFlights($flight);
        $flight->update(['official_quote_payload' => $options[0]]);

        $this->assertNull($options[0]['currency']);
        $this->assertArrayNotHasKey('currency', $service->flightRequestPayload($flight));
        Http::assertSentCount(1);
    }

    public function test_it_returns_empty_options_when_backend_has_no_matches(): void
    {
        Http::fake([
            'https://backend.test/api/v1/client/quotes/preview' => Http::response([
                'success' => true,
                'options' => [],
            ]),
        ]);

        $this->assertSame([], app(FlightApiService::class)->searchFlights($this->flightRequest()));
    }

    public function test_it_rejects_numeric_aircraft_ids_from_preview_options(): void
    {
        Http::fake([
            'https://backend.test/api/v1/client/quotes/preview' => Http::response([
                'options' => [[
                    'aircraft_id' => 164500000000,
                    'aircraft_name' => 'Malformed Jet',
                ]],
            ]),
        ]);

        $this->assertSame([], app(FlightApiService::class)->searchFlights($this->flightRequest()));
    }

    public function test_it_sends_selected_aircraft_when_creating_flight_request(): void
    {
        Http::fake([
            'https://backend.test/api/v1/client/flight-requests' => Http::response([
                'success' => true,
                'flight_request' => ['id' => 3001],
                'accepted_quote' => ['id' => 4001],
            ]),
        ]);

        $flightRequest = $this->flightRequest([
            'selected_aircraft_id' => self::AIRCRAFT_UUID,
            'selected_provider_id' => 201,
            'selected_match_id' => 'match-101',
            'official_quote_payload' => ['currency' => 'USD'],
        ]);

        $response = app(FlightApiService::class)->createFlightRequest($flightRequest);

        $this->assertSame(3001, $response['flight_request']['id']);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://backend.test/api/v1/client/flight-requests'
            && $request->hasHeader('Authorization', 'Bearer plain-api-token')
            && $request['aircraft_id'] === self::AIRCRAFT_UUID
            && $request['provider_id'] === 201
            && $request['match_id'] === 'match-101'
            && $request['currency'] === 'USD'
            && $request['idempotency_key'] === 'whatsapp-'.$flightRequest->id);
    }

    public function test_it_throws_for_unauthorized_backend_response(): void
    {
        $this->assertBackendStatusThrows(401);
    }

    public function test_it_throws_for_forbidden_backend_response(): void
    {
        $this->assertBackendStatusThrows(403);
    }

    public function test_it_throws_for_validation_backend_response(): void
    {
        $this->assertBackendStatusThrows(422);
    }

    public function test_it_throws_for_server_error_backend_response(): void
    {
        $this->assertBackendStatusThrows(500);
    }

    public function test_it_logs_sanitized_backend_quote_preview_failures(): void
    {
        Log::spy();
        Http::fake([
            'https://backend.test/api/v1/client/quotes/preview' => Http::response([
                'status' => 'error',
                'code' => 'SERVICE_ERROR',
                'message' => 'SQLSTATE could not connect to postgresql://user:secret@example.supabase.co/db',
            ], 500),
        ]);

        try {
            app(FlightApiService::class)->searchFlights($this->flightRequest());
            $this->fail('Expected backend failure to throw.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('500', $exception->getMessage());
        }

        Log::shouldHaveReceived('warning')->with('Flight API request failed.', Mockery::on(
            fn (array $context): bool => $context['path'] === '/api/v1/client/quotes/preview'
                && $context['status'] === 500
                && $context['error_code'] === 'SERVICE_ERROR'
                && str_contains($context['message'], 'postgres://[redacted]@')
                && ! str_contains($context['message'], 'secret')
        ));
    }

    public function test_it_throws_for_invalid_json_response(): void
    {
        Http::fake([
            'https://backend.test/api/v1/client/quotes/preview' => Http::response('not-json', 200),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid JSON');

        app(FlightApiService::class)->searchFlights($this->flightRequest());
    }

    public function test_it_throws_when_backend_connection_fails(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection timed out.'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not reachable');

        app(FlightApiService::class)->searchFlights($this->flightRequest());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function flightRequest(array $attributes = []): WhatsAppFlightRequest
    {
        $phoneNumber = '52155'.str_pad((string) WhatsAppContact::query()->count(), 8, '0', STR_PAD_LEFT);

        $contact = WhatsAppContact::query()->create([
            'phone_number' => $phoneNumber,
            'name' => 'Cliente Prueba',
        ]);

        $conversation = WhatsAppConversation::query()->create([
            'whats_app_contact_id' => $contact->id,
            'state' => 'START',
            'status' => 'active',
        ]);

        return WhatsAppFlightRequest::query()->create(array_merge([
            'whats_app_conversation_id' => $conversation->id,
            'origin' => 'Toluca',
            'destination' => 'Cancun',
            'departure_date' => '2026-10-15',
            'departure_time' => '14:30:00',
            'passengers' => 5,
            'trip_type' => 'ONE_WAY',
            'status' => 'collecting',
        ], $attributes));
    }

    private function assertBackendStatusThrows(int $status): void
    {
        Http::fake([
            'https://backend.test/api/v1/client/quotes/preview' => Http::response([
                'success' => false,
                'message' => 'Backend error',
            ], $status),
        ]);

        try {
            app(FlightApiService::class)->searchFlights($this->flightRequest());
            $this->fail("Expected status {$status} to throw.");
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString((string) $status, $exception->getMessage());
        }
    }
}
