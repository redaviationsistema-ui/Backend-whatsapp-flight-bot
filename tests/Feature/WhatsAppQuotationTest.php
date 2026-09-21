<?php

namespace Tests\Feature;

use App\Models\WhatsAppConversation;
use App\Models\WhatsAppFlightRequest;
use App\Services\Flights\FlightApiService;
use App\Services\WhatsApp\WhatsAppChatbotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class WhatsAppQuotationTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['1', 'ONE_WAY'])]
    #[TestWith(['2', 'ROUND_TRIP'])]
    #[TestWith(['3', 'MULTI_CITY'])]
    public function test_collects_and_summarizes_each_trip_before_explicit_confirmation(string $choice, string $tripType): void
    {
        $this->travelTo(Carbon::parse('2026-09-20 12:00:00', 'America/Mexico_City'));
        Http::preventStrayRequests();
        Http::fake(['https://backend.test/*' => Http::response([])]);
        $flight = $this->collect($choice);

        $this->assertSame('SHOW_SUMMARY', $flight->conversation->state);
        $this->assertSame('collecting', $flight->status);
        $this->assertNull($flight->confirmed_at);
        $this->assertSame($tripType, $flight->trip_type);
        $this->assertSame('2026-10-02', $flight->departure_date->toDateString());
        $this->assertSame('15:00:00', $flight->departure_time);
        $this->assertSame('Juan Pérez', $flight->client_name);
        $this->assertSame('juan@example.com', $flight->client_email);
        $this->assertSame(4, $flight->luggage_count);
        $this->assertTrue($flight->has_pets);
        $this->assertSame('sí, un perro de 5 kg', $flight->pets_description);
        $this->assertNull($flight->company);
        $summary = app(WhatsAppChatbotService::class)->summaryMessage($flight);
        $this->assertStringContainsString('Perfecto, esto es lo que tengo hasta ahora:', $summary);
        $this->assertStringContainsString('Horario flexible', $summary);
        $this->assertStringContainsString('Juan Pérez', $summary);
        Http::assertNothingSent();

        $result = $this->answer($flight, '1');

        $this->assertSame('SEARCH_FLIGHTS', $result['state']);
        $this->assertSame('confirmed', $flight->refresh()->status);
        $this->assertNotNull($flight->confirmed_at);
    }

    public function test_round_trip_summary_and_backend_payload_include_return(): void
    {
        $this->travelTo(Carbon::parse('2026-09-20 12:00:00', 'America/Mexico_City'));
        $flight = $this->collect('2');

        $summary = app(WhatsAppChatbotService::class)->summaryMessage($flight);
        $payload = app(FlightApiService::class)->previewPayload($flight);

        $this->assertStringContainsString('Ida y vuelta', $summary);
        $this->assertStringContainsString('Regreso:', $summary);
        $this->assertStringContainsString('5 de octubre', $summary);
        $this->assertStringContainsString('5:00 pm', $summary);
        $this->assertSame('2026-10-05T17:00:00', $payload['return_datetime']);
        $this->assertCount(2, $payload['legs']);
        $this->assertSame('Cancún', $payload['legs'][1]['origin']);
    }

    public function test_multicity_captures_multiple_legs_in_summary_and_matching_payload(): void
    {
        $this->travelTo(Carbon::parse('2026-09-20 12:00:00', 'America/Mexico_City'));
        $flight = $this->collect('3');

        $payload = app(FlightApiService::class)->previewPayload($flight);
        $summary = app(WhatsAppChatbotService::class)->summaryMessage($flight);

        $this->assertSame('multi_city', $payload['trip_type']);
        $this->assertCount(3, $payload['legs']);
        $this->assertSame('Cancún', $payload['legs'][1]['origin']);
        $this->assertSame('Monterrey', $payload['legs'][1]['destination']);
        $this->assertSame('Monterrey', $payload['legs'][2]['origin']);
        $this->assertSame('Toluca', $payload['legs'][2]['destination']);
        $this->assertStringContainsString('Multidestino', $summary);
        $this->assertStringContainsString('Toluca → Cancún → Monterrey → Toluca', $summary);
        $this->assertStringContainsString('Tramo 2: Cancún → Monterrey', $summary);
        $this->assertStringContainsString('Tramo 3: Monterrey → Toluca', $summary);
    }

    public function test_editing_passengers_preserves_other_answers_and_returns_to_summary(): void
    {
        $this->travelTo(Carbon::parse('2026-09-20 12:00:00', 'America/Mexico_City'));
        $flight = $this->collect('1');
        $this->answer($flight, '2');
        $this->answer($flight, '5');

        $result = $this->answer($flight, '7');

        $this->assertSame('SHOW_SUMMARY', $result['state']);
        $this->assertStringContainsString('7 pasajeros', $result['message']);
        $this->assertSame('Toluca', $flight->refresh()->origin);
        $this->assertSame('juan@example.com', $flight->client_email);
        $this->assertNull($flight->confirmed_at);
    }

    public function test_changing_trip_type_collects_return_then_clears_it_when_changed_back(): void
    {
        $this->travelTo(Carbon::parse('2026-09-20 12:00:00', 'America/Mexico_City'));
        $flight = $this->collect('1');
        foreach (['2', '6', '2', '2026-10-05', '17:00'] as $answer) {
            $result = $this->answer($flight, $answer);
        }
        $this->assertSame('SHOW_SUMMARY', $result['state']);
        $this->assertSame('2026-10-05', $flight->refresh()->return_date->toDateString());

        foreach (['2', '6', '1'] as $answer) {
            $result = $this->answer($flight, $answer);
        }
        $this->assertSame('SHOW_SUMMARY', $result['state']);
        $this->assertNull($flight->refresh()->return_date);
        $this->assertNull($flight->return_time);
    }

    public function test_cancel_keeps_draft_data_without_confirming(): void
    {
        $this->travelTo(Carbon::parse('2026-09-20 12:00:00', 'America/Mexico_City'));
        $flight = $this->collect('1');

        $result = $this->answer($flight, '3');

        $this->assertSame('CANCELLED', $result['state']);
        $this->assertSame('cancelled', $flight->refresh()->status);
        $this->assertNull($flight->confirmed_at);
        $this->assertSame('Toluca', $flight->origin);
    }

    #[TestWith(['ASK_ORIGIN', ''])]
    #[TestWith(['ASK_DESTINATION', ' TOLUCA '])]
    #[TestWith(['ASK_DEPARTURE_DATE', '2026-02-30'])]
    #[TestWith(['ASK_DEPARTURE_DATE', '2026-09-19'])]
    #[TestWith(['ASK_DEPARTURE_TIME', '25:30'])]
    #[TestWith(['ASK_PASSENGERS', '0'])]
    #[TestWith(['ASK_PASSENGERS', '-1'])]
    #[TestWith(['ASK_PASSENGERS', '1.5'])]
    #[TestWith(['ASK_EMAIL', 'correo-invalido'])]
    #[TestWith(['ASK_RETURN_DATE', '2026-10-01'])]
    #[TestWith(['ASK_RETURN_TIME', '14:00'])]
    #[TestWith(['ASK_TIME_FLEXIBILITY', 'quizá'])]
    #[TestWith(['ASK_LUGGAGE', '-1'])]
    public function test_invalid_answers_do_not_advance_or_confirm(string $state, string $input): void
    {
        $this->travelTo(Carbon::parse('2026-09-20 12:00:00', 'America/Mexico_City'));
        $flight = WhatsAppFlightRequest::factory()->for(WhatsAppConversation::factory()->state(['state' => $state]), 'conversation')->create([
            'origin' => 'Toluca', 'departure_date' => '2026-10-02', 'departure_time' => '15:00:00', 'return_date' => '2026-10-02',
        ]);

        $result = $this->answer($flight, $input);

        $this->assertSame($state, $result['state']);
        $this->assertNull($flight->refresh()->confirmed_at);
    }

    #[TestWith(['mañana', '2026-09-21'])]
    #[TestWith(['ESTE VIERNES', '2026-09-25'])]
    #[TestWith(['2 de octubre', '2026-10-02'])]
    public function test_normalizes_spanish_dates(string $input, string $expected): void
    {
        $this->travelTo(Carbon::parse('2026-09-20 12:00:00', 'America/Mexico_City'));
        $flight = WhatsAppFlightRequest::factory()->for(WhatsAppConversation::factory()->state(['state' => 'ASK_DEPARTURE_DATE']), 'conversation')->create();

        $this->answer($flight, $input);

        $this->assertSame($expected, $flight->refresh()->departure_date->toDateString());
    }

    public function test_confirmation_revalidates_departure_date_after_waiting(): void
    {
        $this->travelTo(Carbon::parse('2026-09-20 12:00:00', 'America/Mexico_City'));
        $flight = $this->collect('1');
        $this->travelTo(Carbon::parse('2026-10-03 12:00:00', 'America/Mexico_City'));

        $result = $this->answer($flight, '1');

        $this->assertSame('ASK_DEPARTURE_DATE', $result['state']);
        $this->assertNull($flight->refresh()->confirmed_at);
    }

    public function test_legacy_search_state_requires_explicit_confirmation(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://backend.test/*' => Http::response([])]);
        $flight = WhatsAppFlightRequest::factory()->for(WhatsAppConversation::factory()->state(['state' => 'SEARCH_FLIGHTS']), 'conversation')->create(['origin' => 'Toluca']);

        $result = $this->answer($flight, '1');

        $this->assertSame('SHOW_SUMMARY', $result['state']);
        $this->assertStringContainsString('¿Todo está correcto para solicitar la cotización?', $result['message']);
        $this->assertNull($flight->refresh()->confirmed_at);
        Http::assertNothingSent();
    }

    public function test_aircraft_purchase_intent_does_not_start_quote_or_store_location(): void
    {
        $flight = WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()->state(['state' => 'ASK_ORIGIN']), 'conversation')
            ->create();

        $result = $this->answer($flight, 'Estoy interesado en comprarlo, vi una publicación de un Cessna 650');

        $this->assertSame('ASK_ORIGIN', $result['state']);
        $this->assertNull($flight->refresh()->origin);
        $this->assertStringContainsString('exclusivamente en renta y cotización de vuelos privados', $result['message']);
    }

    public function test_invalid_location_answer_stays_in_state_without_saving_text(): void
    {
        $flight = WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()->state(['state' => 'ASK_ORIGIN']), 'conversation')
            ->create();

        $result = $this->answer($flight, 'Estoy interesado en comprarlo');

        $this->assertSame('ASK_ORIGIN', $result['state']);
        $this->assertNull($flight->refresh()->origin);
    }

    public function test_extracts_route_details_from_one_natural_message_and_asks_only_next_missing_question(): void
    {
        $this->travelTo(Carbon::parse('2026-09-21', config('whatsapp.timezone')));
        $flight = WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()->state(['state' => 'ASK_ORIGIN']), 'conversation')
            ->create();

        $result = $this->answer($flight, 'La salida es de Monterrey a Tampico el 23 de septiembre únicamente ida, 4 pasajeros');

        $flight->refresh();
        $this->assertSame('Monterrey', $flight->origin);
        $this->assertSame('Tampico', $flight->destination);
        $this->assertSame('2026-09-23', $flight->departure_date->toDateString());
        $this->assertSame('ONE_WAY', $flight->trip_type);
        $this->assertSame(4, $flight->passengers);
        $this->assertSame('ASK_DEPARTURE_TIME', $result['state']);
        $this->assertStringContainsString('Monterrey → Tampico', $result['message']);
        $this->assertStringContainsString('¿A qué hora te gustaría salir?', $result['message']);
    }

    public function test_valid_route_details_overwrite_previous_invalid_locations(): void
    {
        $this->travelTo(Carbon::parse('2026-09-21', config('whatsapp.timezone')));
        $flight = WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()->state(['state' => 'ASK_ORIGIN']), 'conversation')
            ->create([
                'origin' => 'Estoy interesado en comprarlo',
                'destination' => 'Vi una publicación',
            ]);

        $this->answer($flight, 'La salida es de Monterrey a Tampico el 23 de septiembre únicamente ida, 4 pasajeros');

        $this->assertSame('Monterrey', $flight->refresh()->origin);
        $this->assertSame('Tampico', $flight->destination);
    }

    public function test_invalid_budget_stays_in_budget_state_without_saving_text(): void
    {
        $flight = WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()->state(['state' => 'ASK_BUDGET']), 'conversation')
            ->create();

        $result = $this->answer($flight, 'Demo');

        $this->assertSame('ASK_BUDGET', $result['state']);
        $this->assertNull($flight->refresh()->budget);
        $this->assertStringContainsString('No alcancé a identificar un presupuesto', $result['message']);
    }

    #[TestWith(['ASK_PASSENGERS', '9', 'passengers', 9, 'ASK_TRIP_TYPE', '9 pasajeros'])]
    #[TestWith(['ASK_LUGGAGE_DESCRIPTION', 'Dos bolsas de equipo', 'luggage_description', 'Dos bolsas de equipo', 'ASK_SPECIAL_LUGGAGE', 'Dos bolsas de equipo'])]
    #[TestWith(['ASK_AIRCRAFT_PREFERENCE', 'Cabina amplia', 'aircraft_preference', 'Cabina amplia', 'ASK_ALTERNATE_AIRPORTS', 'Cabina amplia'])]
    #[TestWith(['ASK_NAME', 'María García', 'client_name', 'María García', 'ASK_EMAIL', 'María García'])]
    #[TestWith(['ASK_EMAIL', 'maria@example.org', 'client_email', 'maria@example.org', 'ASK_COMPANY', 'maria@example.org'])]
    #[TestWith(['ASK_COMPANY', 'omitir', 'company', null, 'ASK_BUDGET', 'Perfecto, esto es lo que tengo hasta ahora'])]
    #[TestWith(['ASK_PETS', 'no', 'has_pets', false, 'ASK_AIRCRAFT_PREFERENCE', 'Sin mascotas'])]
    public function test_answers_and_summary_use_only_captured_values(string $state, string $input, string $field, mixed $expected, string $nextState, string $summaryLine): void
    {
        $flight = WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()->state(['state' => $state]), 'conversation')
            ->create();

        $result = $this->answer($flight, $input);

        $this->assertSame($expected, $flight->refresh()->{$field});
        $this->assertSame($nextState, $result['state']);
        $this->assertStringContainsString($summaryLine, app(WhatsAppChatbotService::class)->summaryMessage($flight));
    }

    private function collect(string $trip): WhatsAppFlightRequest
    {
        $flight = WhatsAppFlightRequest::factory()->create();
        $answers = ['hola', 'Toluca', 'Cancún', '2 de octubre', '3 de la tarde', 'SÍ', '4', $trip];
        $answers = [...$answers, ...match ($trip) {
            '2' => ['2026-10-05', '17:00'],
            '3' => ['Monterrey', '2026-10-05', '15:00', 'Toluca', '2026-10-07', '14:30', 'listo'],
            default => [],
        }, '4', '4 maletas', 'ninguno', 'sí, un perro de 5 kg', 'sin preferencia', 'sí', 'sí', 'no', 'sí', 'ninguno', 'Juan Pérez', 'juan@example.com', 'omitir', '50000 USD', 'ninguna'];
        foreach ($answers as $answer) {
            $this->answer($flight, $answer);
        }

        return $flight->refresh()->load('conversation');
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
