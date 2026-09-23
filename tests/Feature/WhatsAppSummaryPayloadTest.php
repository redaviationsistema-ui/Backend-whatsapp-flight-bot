<?php

namespace Tests\Feature;

use App\Models\WhatsAppFlightRequest;
use App\Services\Flights\FlightApiService;
use App\Services\WhatsApp\WhatsAppChatbotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class WhatsAppSummaryPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_round_trip_summary_database_and_payload_share_same_semantics(): void
    {
        $flight = $this->flight([
            'trip_type' => 'ROUND_TRIP',
            'return_date' => '2026-10-05',
            'return_time' => '18:00:00',
            'passengers' => 7,
            'company' => 'Sky Test',
            'budget' => 25000,
        ]);

        $summary = app(WhatsAppChatbotService::class)->summaryMessage($flight);
        $payload = app(FlightApiService::class)->previewPayload($flight);

        $this->assertStringContainsString($flight->origin, $summary);
        $this->assertStringContainsString($flight->destination, $summary);
        $this->assertStringContainsString('7 pasajeros', $summary);
        $this->assertStringContainsString('Regreso:', $summary);
        $this->assertSame($flight->origin, $payload['origin']);
        $this->assertSame($flight->destination, $payload['destination']);
        $this->assertSame($flight->passengers, $payload['passengers']);
        $this->assertSame('round_trip', $payload['trip_type']);
        $this->assertSame('2026-10-05T18:00:00', $payload['return_datetime']);
        $this->assertSame($flight->destination, $payload['legs'][1]['origin']);
        $this->assertStringContainsString('Sky Test', $summary);
        $this->assertStringContainsString('25,000', $summary);
    }

    public function test_multicity_summary_database_and_payload_keep_leg_order(): void
    {
        $flight = $this->flight([
            'trip_type' => 'MULTI_CITY',
            'destination' => 'Morelia',
            'legs' => [
                ['origin' => 'Morelia', 'destination' => 'Monterrey', 'departure_date' => '2026-10-03', 'departure_time' => '12:00:00'],
                ['origin' => 'Monterrey', 'destination' => 'Cancún', 'departure_date' => '2026-10-04', 'departure_time' => '13:00:00'],
            ],
        ]);

        $summary = app(WhatsAppChatbotService::class)->summaryMessage($flight);
        $payload = app(FlightApiService::class)->previewPayload($flight);

        $this->assertStringContainsString('Toluca → Morelia → Monterrey → Cancún', $summary);
        $this->assertSame('multi_city', $payload['trip_type']);
        $this->assertSame(['Toluca', 'Morelia', 'Monterrey'], array_column($payload['legs'], 'origin'));
        $this->assertSame(['Morelia', 'Monterrey', 'Cancún'], array_column($payload['legs'], 'destination'));
    }

    #[TestWith([[], true])]
    #[TestWith([['origin' => null], false])]
    #[TestWith([['destination' => null], false])]
    #[TestWith([['departure_date' => null], false])]
    #[TestWith([['departure_time' => null], false])]
    #[TestWith([['passengers' => null], false])]
    #[TestWith([['trip_type' => null], false])]
    #[TestWith([['trip_type' => 'ROUND_TRIP', 'return_date' => null], false])]
    #[TestWith([['trip_type' => 'ROUND_TRIP', 'return_time' => null], false])]
    #[TestWith([['trip_type' => 'MULTI_CITY', 'legs' => null], false])]
    #[TestWith([['company' => null, 'budget' => null], true])]
    public function test_quote_readiness_requires_only_mandatory_fields(array $overrides, bool $expected): void
    {
        $flight = $this->flight($overrides);

        $this->assertSame($expected, app(WhatsAppChatbotService::class)->isQuoteReady($flight));
    }

    /** @param array<string, mixed> $attributes */
    private function flight(array $attributes = []): WhatsAppFlightRequest
    {
        return WhatsAppFlightRequest::factory()->create([
            'origin' => 'Toluca',
            'destination' => 'Cancún',
            'departure_date' => '2026-10-02',
            'departure_time' => '15:00:00',
            'passengers' => 4,
            'trip_type' => 'ONE_WAY',
            'is_time_flexible' => false,
            'allow_alternate_airports' => true,
            'aircraft_preference' => 'sin preferencia',
            'other_services' => 'ninguno',
            'client_name' => 'Kevin Lael',
            'client_email' => 'kevin@example.com',
            'notes' => 'sin notas',
            ...$attributes,
        ]);
    }
}
