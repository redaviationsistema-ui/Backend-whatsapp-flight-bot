<?php

namespace Tests\Feature;

use App\Models\WhatsAppFlightRequest;
use App\Services\Flights\FlightApiService;
use App\Services\WhatsApp\WhatsAppChatbotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

    public function test_traditional_return_signal_creates_inverse_round_trip(): void
    {
        $this->travelTo(Carbon::parse('2026-09-22', config('whatsapp.timezone')));
        $flight = $this->flight([
            'trip_type' => null,
            'return_date' => null,
            'return_time' => null,
            'legs' => null,
        ]);

        $result = $this->answer($flight, 'Regresamos el domingo.');

        $this->assertSame('ASK_RETURN_TIME', $result['state']);
        $this->assertSame('ROUND_TRIP', $flight->trip_type);
        $this->assertSame('2026-10-04', $flight->return_date->toDateString());
        $this->assertNull($flight->return_time);
    }

    public function test_open_jaw_return_from_different_origin_does_not_force_initial_destination(): void
    {
        $this->travelTo(Carbon::parse('2026-09-22', config('whatsapp.timezone')));
        $flight = $this->flight(['trip_type' => null, 'legs' => null]);

        $result = $this->answer($flight, 'Regresamos desde Mérida a Toluca el domingo a las 6 pm.');

        $flight->refresh();
        $payload = app(FlightApiService::class)->previewPayload($flight);
        $this->assertSame('SHOW_SUMMARY', $result['state']);
        $this->assertSame('MULTI_CITY', $flight->trip_type);
        $this->assertSame('Mérida', $flight->legs[0]['origin']);
        $this->assertSame('Toluca', $flight->legs[0]['destination']);
        $this->assertSame('Mérida', $payload['legs'][1]['origin']);
        $this->assertSame('Toluca', $payload['legs'][1]['destination']);
        $this->assertStringContainsString('Toluca → Cancún / Mérida → Toluca', app(WhatsAppChatbotService::class)->summaryMessage($flight));
    }

    public function test_complete_open_jaw_return_extracts_route_date_and_time(): void
    {
        $this->travelTo(Carbon::parse('2026-09-22', config('whatsapp.timezone')));
        $flight = $this->flight(['trip_type' => null, 'legs' => null]);

        $result = $this->answer($flight, 'Regresamos de Mérida a CDMX el domingo a las 6 pm.');

        $flight->refresh();
        $this->assertSame('SHOW_SUMMARY', $result['state']);
        $this->assertSame('MULTI_CITY', $flight->trip_type);
        $this->assertSame([[
            'origin' => 'Mérida',
            'destination' => 'CDMX',
            'departure_date' => '2026-10-04',
            'departure_time' => '18:00:00',
        ]], $flight->legs);
        $this->assertTrue(app(WhatsAppChatbotService::class)->isQuoteReady($flight));
    }

    public function test_partial_open_jaw_return_asks_only_missing_destination(): void
    {
        $this->travelTo(Carbon::parse('2026-09-22', config('whatsapp.timezone')));
        $flight = $this->flight(['trip_type' => null, 'legs' => null]);

        $result = $this->answer($flight, 'Regresamos desde Mérida el domingo a las 6 pm.');

        $flight->refresh();
        $this->assertSame('ASK_LEGS', $result['state']);
        $this->assertSame('Mérida', $flight->legs[0]['origin']);
        $this->assertNull($flight->legs[0]['destination']);
        $this->assertStringContainsString('llega el tramo que sale de Mérida', $result['message']);
    }

    public function test_return_to_different_destination_keeps_initial_destination_as_return_origin(): void
    {
        $this->travelTo(Carbon::parse('2026-09-22', config('whatsapp.timezone')));
        $flight = $this->flight(['trip_type' => null, 'legs' => null]);

        $result = $this->answer($flight, 'Regresamos a CDMX el domingo a las 6 pm.');

        $flight->refresh();
        $this->assertSame('SHOW_SUMMARY', $result['state']);
        $this->assertSame('MULTI_CITY', $flight->trip_type);
        $this->assertSame('Cancún', $flight->legs[0]['origin']);
        $this->assertSame('CDMX', $flight->legs[0]['destination']);
    }

    public function test_two_leg_open_jaw_can_be_extracted_from_one_message(): void
    {
        $this->travelTo(Carbon::parse('2026-09-22', config('whatsapp.timezone')));
        $flight = WhatsAppFlightRequest::factory()->create();

        $result = $this->answer($flight, 'De Toluca a Cancún este viernes a las 8 am y de Mérida a CDMX el domingo a las 6 pm.');

        $flight->refresh();
        $payload = app(FlightApiService::class)->previewPayload($flight);
        $this->assertSame('ASK_PASSENGERS', $result['state']);
        $this->assertSame('MULTI_CITY', $flight->trip_type);
        $this->assertSame('Toluca', $flight->origin);
        $this->assertSame('Cancún', $flight->destination);
        $this->assertSame('Mérida', $flight->legs[0]['origin']);
        $this->assertSame('CDMX', $flight->legs[0]['destination']);
        $this->assertSame(['Toluca', 'Mérida'], array_column($payload['legs'], 'origin'));
        $this->assertSame(['Cancún', 'CDMX'], array_column($payload['legs'], 'destination'));
    }

    public function test_clause_aware_open_jaw_dates_stay_attached_to_their_leg(): void
    {
        $this->travelTo(Carbon::parse('2026-09-23 12:00:00', config('whatsapp.timezone')));
        $flight = WhatsAppFlightRequest::factory()->create();

        $result = $this->answer($flight, 'Quiero volar de Toluca a Cancún el viernes y regresar el domingo desde Mérida a CDMX.');

        $flight->refresh();
        $this->assertSame('ASK_DEPARTURE_TIME', $result['state']);
        $this->assertSame('MULTI_CITY', $flight->trip_type);
        $this->assertSame('Toluca', $flight->origin);
        $this->assertSame('Cancún', $flight->destination);
        $this->assertSame('2026-09-25', $flight->departure_date->toDateString());
        $this->assertNull($flight->departure_time);
        $this->assertSame('Mérida', $flight->legs[0]['origin']);
        $this->assertSame('CDMX', $flight->legs[0]['destination']);
        $this->assertSame('2026-09-27', $flight->legs[0]['departure_date']);
        $this->assertNull($flight->legs[0]['departure_time']);
        $this->assertNotSame($flight->departure_date->toDateString(), $flight->legs[0]['departure_date']);
        $this->assertStringNotContainsString('Desde qué ciudad', $result['message']);
        $this->assertStringContainsString('A qué hora', $result['message']);
    }

    public function test_second_leg_correction_does_not_modify_first_leg(): void
    {
        $flight = $this->flight([
            'trip_type' => 'MULTI_CITY',
            'legs' => [[
                'origin' => 'Mérida',
                'destination' => null,
                'departure_date' => '2026-10-05',
                'departure_time' => '18:00:00',
            ]],
        ]);
        $flight->conversation()->update(['state' => 'ASK_LEGS']);

        $result = $this->answer($flight, 'CDMX');

        $flight->refresh();
        $this->assertSame('SHOW_SUMMARY', $result['state']);
        $this->assertSame('Toluca', $flight->origin);
        $this->assertSame('Cancún', $flight->destination);
        $this->assertSame('Mérida', $flight->legs[0]['origin']);
        $this->assertSame('CDMX', $flight->legs[0]['destination']);
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

    /** @return array{state:string,message:string} */
    private function answer(WhatsAppFlightRequest $flight, string $answer): array
    {
        $conversation = $flight->conversation()->firstOrFail();
        $result = app(WhatsAppChatbotService::class)->handleIncomingMessage($conversation, $flight->refresh(), $answer);
        $conversation->update(['state' => $result['state']]);

        return $result;
    }
}
