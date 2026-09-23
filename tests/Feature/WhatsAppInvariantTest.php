<?php

namespace Tests\Feature;

use App\Models\WhatsAppConversation;
use App\Models\WhatsAppFlightRequest;
use App\Services\Flights\FlightApiService;
use App\Services\WhatsApp\WhatsAppChatbotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class WhatsAppInvariantTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith([['origin' => null], 'ASK_ORIGIN'])]
    #[TestWith([['destination' => null], 'ASK_DESTINATION'])]
    #[TestWith([['passengers' => null], 'ASK_PASSENGERS'])]
    #[TestWith([['trip_type' => 'ROUND_TRIP', 'return_date' => '2026-10-01'], 'ASK_RETURN_DATE'])]
    #[TestWith([['origin' => 'Toluca', 'destination' => 'Toluca'], 'ASK_DESTINATION'])]
    public function test_incomplete_or_invalid_request_never_confirms(array $overrides, string $expectedState): void
    {
        $flight = $this->completeFlight($overrides);
        $flight->conversation()->update(['state' => 'SHOW_SUMMARY']);

        $result = $this->answer($flight, 'sí');

        $this->assertSame($expectedState, $result['state']);
        $this->assertSame('collecting', $flight->refresh()->status);
        $this->assertNull($flight->confirmed_at);
    }

    #[DataProvider('unknownInputsProvider')]
    public function test_unknown_or_ambiguous_input_preserves_state_and_confirmed_data(string $state, string $input, array $attributes): void
    {
        $flight = WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()->state(['state' => $state]), 'conversation')
            ->create($attributes);
        $before = $flight->only(['origin', 'destination', 'departure_date', 'departure_time', 'passengers', 'trip_type', 'return_date', 'return_time']);

        $result = $this->answer($flight, $input);

        $flight->refresh();
        $this->assertSame($state, $result['state']);
        foreach ($before as $field => $value) {
            $this->assertEquals($value, $flight->{$field});
        }
    }

    /** @return array<string, array{state:string,input:string,attributes:array<string, mixed>}> */
    public static function unknownInputsProvider(): array
    {
        return [
            'origin_connector_only' => ['state' => 'ASK_ORIGIN', 'input' => 'desde', 'attributes' => []],
            'origin_mumbling' => ['state' => 'ASK_ORIGIN', 'input' => 'mmm pues este', 'attributes' => []],
            'passengers_no_number' => ['state' => 'ASK_PASSENGERS', 'input' => 'somos varios', 'attributes' => ['passengers' => 4]],
            'time_period_without_hour' => ['state' => 'ASK_DEPARTURE_TIME', 'input' => 'temprano', 'attributes' => ['departure_time' => '15:00:00']],
            'email_invalid' => ['state' => 'ASK_EMAIL', 'input' => 'kevin gmail.con', 'attributes' => ['client_email' => 'old@example.com']],
        ];
    }

    public function test_correction_by_negation_only_changes_destination(): void
    {
        $flight = $this->completeFlight([
            'destination' => 'Cancún',
            'departure_date' => '2026-10-02',
            'departure_time' => '15:00:00',
            'passengers' => 4,
        ]);
        $before = $flight->only(['origin', 'departure_date', 'departure_time', 'passengers', 'trip_type']);

        $result = $this->answer($flight, 'No Cancún, Mérida.');

        $flight->refresh();
        $this->assertSame('SHOW_SUMMARY', $result['state']);
        $this->assertSame('Mérida', $flight->destination);
        foreach ($before as $field => $value) {
            $this->assertEquals($value, $flight->{$field});
        }
    }

    public function test_contextual_same_time_reference_uses_departure_time_for_later_return_date(): void
    {
        $flight = $this->completeFlight([
            'trip_type' => 'ROUND_TRIP',
            'departure_date' => '2026-10-02',
            'departure_time' => '15:00:00',
            'return_date' => '2026-10-04',
            'return_time' => null,
        ]);
        $flight->conversation()->update(['state' => 'ASK_RETURN_TIME']);

        $result = $this->answer($flight, 'la misma hora');

        $this->assertSame('SHOW_SUMMARY', $result['state']);
        $this->assertSame('15:00:00', $flight->refresh()->return_time);
    }

    public function test_keep_the_rest_equal_preserves_current_values_and_continues(): void
    {
        $flight = $this->completeFlight(['client_email' => null]);
        $flight->conversation()->update(['state' => 'ASK_EMAIL']);
        $before = $flight->only(['origin', 'destination', 'departure_date', 'departure_time', 'passengers', 'trip_type']);

        $result = $this->answer($flight, 'lo demás igual');

        $this->assertSame('ASK_EMAIL', $result['state']);
        $this->assertStringContainsString('conservo lo demás igual', $result['message']);
        foreach ($before as $field => $value) {
            $this->assertEquals($value, $flight->refresh()->{$field});
        }
    }

    public function test_current_itinerary_question_shows_summary_without_capturing_answer(): void
    {
        $flight = $this->completeFlight(['client_email' => null]);
        $flight->conversation()->update(['state' => 'ASK_EMAIL']);
        $before = $flight->only(['origin', 'destination', 'departure_date', 'departure_time', 'passengers', 'trip_type', 'client_email']);

        $result = $this->answer($flight, '¿Cómo quedaron las rutas?');

        $flight->refresh();
        $this->assertSame('ASK_EMAIL', $result['state']);
        $this->assertStringContainsString('Perfecto, esto es lo que tengo hasta ahora:', $result['message']);
        $this->assertStringContainsString('Toluca → Cancún', $result['message']);
        $this->assertStringContainsString('correo', mb_strtolower($result['message']));
        foreach ($before as $field => $value) {
            $this->assertEquals($value, $flight->{$field});
        }
    }

    public function test_contextual_that_leg_date_patch_updates_only_referenced_leg(): void
    {
        $this->travelTo(Carbon::parse('2026-09-23', config('whatsapp.timezone')));
        $flight = $this->completeFlight([
            'trip_type' => 'MULTI_CITY',
            'destination' => 'Bravo',
            'legs' => [
                ['origin' => 'Bravo', 'destination' => 'Charlie', 'departure_date' => '2026-10-03', 'departure_time' => '12:00:00'],
                ['origin' => 'Charlie', 'destination' => 'Delta', 'departure_date' => '2026-10-04', 'departure_time' => '13:00:00'],
            ],
        ]);
        $flight->conversation()->update([
            'state' => 'SHOW_SUMMARY',
            'metadata' => ['last_referenced_leg_ref' => 1],
        ]);

        $result = $this->answer($flight, 'Mejor ese tramo el 3 de octubre.');

        $flight->refresh();
        $this->assertSame('SHOW_SUMMARY', $result['state']);
        $this->assertSame('2026-10-02', $flight->departure_date->toDateString());
        $this->assertSame('2026-10-03', $flight->legs[0]['departure_date']);
        $this->assertSame('12:00:00', $flight->legs[0]['departure_time']);
        $this->assertSame('2026-10-04', $flight->legs[1]['departure_date']);
    }

    public function test_same_time_on_named_leg_preserves_that_leg_time(): void
    {
        $this->travelTo(Carbon::parse('2026-09-23', config('whatsapp.timezone')));
        $flight = $this->completeFlight([
            'trip_type' => 'MULTI_CITY',
            'departure_time' => '07:00:00',
            'destination' => 'Bravo',
            'legs' => [
                ['origin' => 'Bravo', 'destination' => 'Charlie', 'departure_date' => '2026-10-03', 'departure_time' => '12:00:00'],
                ['origin' => 'Charlie', 'destination' => 'Delta', 'departure_date' => '2026-10-05', 'departure_time' => '13:00:00'],
            ],
        ]);
        $flight->conversation()->update(['state' => 'SHOW_SUMMARY']);

        $result = $this->answer($flight, 'El tramo Bravo a Charlie muévelo al 4 de octubre, a la misma hora.');

        $flight->refresh();
        $this->assertSame('SHOW_SUMMARY', $result['state']);
        $this->assertSame('2026-10-04', $flight->legs[0]['departure_date']);
        $this->assertSame('12:00:00', $flight->legs[0]['departure_time']);
        $this->assertSame('07:00:00', $flight->departure_time);
        $this->assertSame('13:00:00', $flight->legs[1]['departure_time']);
    }

    #[DataProvider('locationReplacementProvider')]
    public function test_replace_location_preserves_unaffected_itinerary_fields(array $route): void
    {
        $flight = $this->completeFlight([
            'origin' => $route['a'],
            'destination' => $route['b'],
            'trip_type' => 'MULTI_CITY',
            'legs' => [
                ['origin' => $route['b'], 'destination' => $route['c'], 'departure_date' => '2026-10-03', 'departure_time' => '12:00:00'],
                ['origin' => $route['c'], 'destination' => $route['d'], 'departure_date' => '2026-10-04', 'departure_time' => '13:00:00'],
            ],
        ]);
        $flight->conversation()->update(['state' => 'SHOW_SUMMARY']);

        $result = $this->answer($flight, "Cambia {$route['b']} por {$route['x']} y deja todo lo demás igual.");

        $flight->refresh();
        $this->assertSame('SHOW_SUMMARY', $result['state']);
        $this->assertSame($route['a'], $flight->origin);
        $this->assertSame($route['x'], $flight->destination);
        $this->assertSame($route['x'], $flight->legs[0]['origin']);
        $this->assertSame($route['c'], $flight->legs[0]['destination']);
        $this->assertSame('2026-10-03', $flight->legs[0]['departure_date']);
        $this->assertSame('12:00:00', $flight->legs[0]['departure_time']);
        $this->assertSame($route['d'], $flight->legs[1]['destination']);
    }

    /** @return array<string, array{route:array{a:string,b:string,c:string,d:string,x:string}}> */
    public static function locationReplacementProvider(): array
    {
        return [
            'abstract_route_one' => ['route' => ['a' => 'Alpha City', 'b' => 'Bravo City', 'c' => 'Charlie City', 'd' => 'Delta City', 'x' => 'Echo City']],
            'abstract_route_two' => ['route' => ['a' => 'Lima Norte', 'b' => 'Mango Norte', 'c' => 'Nectar Norte', 'd' => 'Olivo Norte', 'x' => 'Pino Norte']],
        ];
    }

    #[TestWith(['Need a charter from Toluca to Cancun Friday night.', 'Toluca', 'Cancun'])]
    #[TestWith(['TLC', 'TLC', null])]
    #[TestWith(['MMTO', 'MMTO', null])]
    public function test_airport_codes_and_spanglish_routes_are_safe_location_inputs(string $input, string $expectedOrigin, ?string $expectedDestination): void
    {
        $flight = WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()->state(['state' => 'ASK_ORIGIN']), 'conversation')
            ->create();

        $result = $this->answer($flight, $input);

        $flight->refresh();
        $this->assertSame($expectedOrigin, $flight->origin);
        $this->assertSame($expectedDestination, $flight->destination);
        $this->assertNotSame('ASK_ORIGIN', $result['state']);
    }

    public function test_summary_database_and_payload_invariants_hold_for_all_supported_trip_types(): void
    {
        foreach ([
            'one_way' => $this->completeFlight(['trip_type' => 'ONE_WAY']),
            'round_trip' => $this->completeFlight(['trip_type' => 'ROUND_TRIP', 'return_date' => '2026-10-05', 'return_time' => '18:00:00']),
            'multi_city' => $this->completeFlight([
                'trip_type' => 'MULTI_CITY',
                'destination' => 'Morelia',
                'legs' => [
                    ['origin' => 'Morelia', 'destination' => 'Monterrey', 'departure_date' => '2026-10-03', 'departure_time' => '12:00:00'],
                    ['origin' => 'Monterrey', 'destination' => 'Cancún', 'departure_date' => '2026-10-04', 'departure_time' => '13:00:00'],
                ],
            ]),
        ] as $flight) {
            $summary = app(WhatsAppChatbotService::class)->summaryMessage($flight);
            $payload = app(FlightApiService::class)->previewPayload($flight);

            $this->assertTrue(app(WhatsAppChatbotService::class)->isQuoteReady($flight));
            $this->assertSame($flight->origin, $payload['origin']);
            $this->assertSame($flight->destination, $payload['destination']);
            $this->assertSame($flight->passengers, $payload['passengers']);
            $this->assertStringContainsString($flight->origin, $summary);
            $this->assertStringContainsString($flight->destination, $summary);
            $this->assertNotSame($flight->origin, $flight->destination);
            $this->assertGreaterThan(0, $flight->passengers);
        }
    }

    /** @return array{state:string,message:string} */
    private function answer(WhatsAppFlightRequest $flight, string $answer): array
    {
        $conversation = $flight->conversation()->firstOrFail();
        $result = app(WhatsAppChatbotService::class)->handleIncomingMessage($conversation, $flight->refresh(), $answer);
        $conversation->update(['state' => $result['state']]);

        return $result;
    }

    /** @param array<string, mixed> $attributes */
    private function completeFlight(array $attributes = []): WhatsAppFlightRequest
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
