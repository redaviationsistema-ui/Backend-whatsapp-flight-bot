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
        $this->assertStringContainsString('Resumen de solicitud', $summary);
        $this->assertStringContainsString('Horario flexible: Sí', $summary);
        $this->assertStringContainsString('Nombre: Juan Pérez', $summary);
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

        $this->assertStringContainsString('Fecha de regreso: 2026-10-05', $summary);
        $this->assertStringContainsString('Hora de regreso: 17:00:00', $summary);
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
        $this->assertSame('Monterrey', $payload['legs'][1]['destination']);
        $this->assertSame('Monterrey', $payload['legs'][2]['origin']);
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
        $this->assertStringContainsString('Pasajeros: 7', $result['message']);
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
        $this->assertStringContainsString('Sí, solicitar cotización', $result['message']);
        $this->assertNull($flight->refresh()->confirmed_at);
        Http::assertNothingSent();
    }

    private function collect(string $trip): WhatsAppFlightRequest
    {
        $flight = WhatsAppFlightRequest::factory()->create();
        $answers = ['hola', 'Toluca', 'Cancún', '2 de octubre', '3 de la tarde', 'SÍ', '4', $trip];
        $answers = [...$answers, ...match ($trip) {
            '2' => ['2026-10-05', '17:00'],
            '3' => ['Monterrey | 2026-10-05 | 15:00', 'Toluca | 2026-10-07 | 14:30', 'listo'],
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
