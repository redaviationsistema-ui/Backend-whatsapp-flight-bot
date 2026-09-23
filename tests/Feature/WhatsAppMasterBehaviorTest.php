<?php

namespace Tests\Feature;

use App\Models\WhatsAppContact;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppFlightRequest;
use App\Models\WhatsAppMessage;
use App\Services\Flights\FlightApiService;
use App\Services\WhatsApp\WhatsAppChatbotService;
use App\Services\WhatsApp\WhatsAppConversationService;
use Faker\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class WhatsAppMasterBehaviorTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['No quiero volar'])]
    #[TestWith(['No necesito un vuelo'])]
    #[TestWith(['No estoy buscando renta'])]
    public function test_rejection_does_not_become_origin(string $input): void
    {
        $flight = WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()->state(['state' => 'ASK_ORIGIN']), 'conversation')
            ->create();

        $result = $this->answer($flight, $input);

        $this->assertSame('START', $result['state']);
        $this->assertNull($flight->refresh()->origin);
        $this->assertStringContainsString('Si más adelante necesitas cotizar un vuelo privado', $result['message']);
    }

    #[TestWith(['Toluca, Morelia, Monterrey y Cancún'])]
    #[TestWith(['Toluca - Morelia - Monterrey - Cancún'])]
    #[TestWith(['salgo de Toluca, voy a Morelia, después Monterrey y termino en Cancún'])]
    public function test_multicity_route_variants_preserve_order(string $input): void
    {
        $flight = WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()->state(['state' => 'ASK_ORIGIN']), 'conversation')
            ->create();

        $result = $this->answer($flight, $input);

        $flight->refresh();
        $this->assertSame('Toluca', $flight->origin);
        $this->assertSame('Morelia', $flight->destination);
        $this->assertSame('MULTI_CITY', $flight->trip_type);
        $this->assertSame('Monterrey', $flight->legs[0]['destination']);
        $this->assertSame('Cancún', $flight->legs[1]['destination']);
        $this->assertStringContainsString('Toluca → Morelia → Monterrey → Cancún', $result['message']);
    }

    public function test_duplicate_route_is_rejected_without_saving_destination(): void
    {
        $flight = WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()->state(['state' => 'ASK_ORIGIN']), 'conversation')
            ->create();

        $result = $this->answer($flight, 'Toluca a Toluca');

        $this->assertSame('ASK_ORIGIN', $result['state']);
        $this->assertNull($flight->refresh()->origin);
        $this->assertNull($flight->destination);
    }

    public function test_ambiguous_hour_does_not_save_or_advance(): void
    {
        $flight = WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()->state(['state' => 'ASK_DEPARTURE_TIME']), 'conversation')
            ->create();

        $result = $this->answer($flight, '2');

        $this->assertSame('ASK_DEPARTURE_TIME', $result['state']);
        $this->assertNull($flight->refresh()->departure_time);
        $this->assertStringContainsString('tarde o las 2 de la mañana', $result['message']);
    }

    public function test_incomplete_name_does_not_advance(): void
    {
        $flight = WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()->state(['state' => 'ASK_NAME']), 'conversation')
            ->create();

        $result = $this->answer($flight, 'Kevin');

        $this->assertSame('ASK_NAME', $result['state']);
        $this->assertNull($flight->refresh()->client_name);
    }

    public function test_security_like_input_does_not_save_as_location_or_crash(): void
    {
        $flight = WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()->state(['state' => 'ASK_ORIGIN']), 'conversation')
            ->create();

        $result = $this->answer($flight, "' OR 1=1 --");

        $this->assertSame('ASK_ORIGIN', $result['state']);
        $this->assertNull($flight->refresh()->origin);
    }

    public function test_summary_omits_false_or_null_optional_noise(): void
    {
        $flight = WhatsAppFlightRequest::factory()->create([
            'origin' => 'Toluca',
            'destination' => 'Cancún',
            'departure_date' => '2026-10-02',
            'departure_time' => '15:00:00',
            'passengers' => 4,
            'trip_type' => 'ONE_WAY',
            'is_time_flexible' => null,
            'allow_alternate_airports' => false,
            'company' => null,
            'budget' => null,
        ]);

        $summary = app(WhatsAppChatbotService::class)->summaryMessage($flight);

        $this->assertStringContainsString('Toluca → Cancún', $summary);
        $this->assertStringNotContainsString('Aeropuertos alternos', $summary);
        $this->assertStringNotContainsString('Presupuesto', $summary);
        $this->assertStringNotContainsString('Cotización para', $summary);
    }

    public function test_non_text_message_gets_safe_prompt_without_advancing(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*/123/messages' => Http::response(['messages' => [['id' => 'out.image']]])]);

        $this->webhook(['messages' => [[
            'id' => 'in.image',
            'from' => '5215512345678',
            'type' => 'image',
            'image' => ['id' => 'media-1'],
        ]]])->assertOk();

        $conversation = WhatsAppConversation::query()->sole();
        $this->assertSame('START', $conversation->state);
        $this->assertDatabaseHas('whats_app_messages', [
            'message_id' => 'out.image',
            'body' => 'Por favor responde con texto para continuar.',
        ]);
    }

    #[TestWith(['ASK_TIME_FLEXIBILITY', 'No', 'is_time_flexible', false, 'ASK_ALTERNATE_AIRPORTS'])]
    #[TestWith(['ASK_TIME_FLEXIBILITY', 'Sí', 'is_time_flexible', true, 'ASK_ALTERNATE_AIRPORTS'])]
    #[TestWith(['ASK_ALTERNATE_AIRPORTS', 'No', 'allow_alternate_airports', false, 'ASK_OTHER_SERVICES'])]
    public function test_contextual_yes_no_answers_update_only_current_boolean(string $state, string $input, string $field, bool $expected, string $nextState): void
    {
        $flight = WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()->state(['state' => $state]), 'conversation')
            ->create();

        $result = $this->answer($flight, $input);

        $this->assertSame($nextState, $result['state']);
        $this->assertSame($expected, $flight->refresh()->{$field});
    }

    #[TestWith(['No', 'START'])]
    #[TestWith(['Sí', 'ASK_ORIGIN'])]
    public function test_out_of_scope_follow_up_yes_no_is_contextual_and_clean(string $input, string $expectedState): void
    {
        $flight = WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()->state(['state' => 'ASK_ORIGIN']), 'conversation')
            ->create([
                'origin' => 'Toluca',
                'destination' => 'Cancún',
                'departure_date' => '2026-10-02',
                'departure_time' => '15:00:00',
                'passengers' => 4,
                'trip_type' => 'ONE_WAY',
            ]);

        $this->answer($flight, 'Quiero comprar un avión');
        $result = $this->answer($flight, $input);

        $this->assertSame($expectedState, $result['state']);
        $flight->refresh();
        $this->assertNull($flight->origin);
        $this->assertNull($flight->destination);
        $this->assertNull($flight->departure_date);
        $this->assertNull($flight->passengers);
        $this->assertNull($flight->trip_type);
    }

    public function test_new_conversation_for_same_contact_does_not_inherit_previous_request(): void
    {
        $contact = WhatsAppContact::factory()->create(['phone_number' => '5215512345678']);
        $conversationA = WhatsAppConversation::factory()->for($contact, 'contact')->create(['state' => 'FINISHED', 'is_active' => false]);
        WhatsAppFlightRequest::factory()->for($conversationA, 'conversation')->create([
            'origin' => 'Toluca',
            'destination' => 'Cancún',
            'departure_date' => '2026-10-02',
            'departure_time' => '15:00:00',
            'passengers' => 4,
            'trip_type' => 'ONE_WAY',
            'legs' => [['origin' => 'Cancún', 'destination' => 'Mérida']],
        ]);

        $conversationB = app(WhatsAppConversationService::class)->findOrCreateActiveConversation($contact);
        $flightB = app(WhatsAppConversationService::class)->findOrCreateFlightRequest($conversationB);

        $this->assertNotSame($conversationA->id, $conversationB->id);
        foreach (['origin', 'destination', 'departure_date', 'departure_time', 'passengers', 'trip_type', 'legs'] as $field) {
            $this->assertNull($flightB->{$field});
        }
    }

    public function test_round_trip_summary_and_payload_preserve_return_and_reject_earlier_return(): void
    {
        $flight = $this->completeFlight(['trip_type' => 'ROUND_TRIP', 'return_date' => '2026-10-05', 'return_time' => '18:00:00']);
        $summary = app(WhatsAppChatbotService::class)->summaryMessage($flight);
        $payload = app(FlightApiService::class)->previewPayload($flight);

        $this->assertStringContainsString('Regreso:', $summary);
        $this->assertSame('2026-10-05T18:00:00', $payload['return_datetime']);
        $this->assertSame('Cancún', $payload['legs'][1]['origin']);

        $invalid = WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()->state(['state' => 'ASK_RETURN_DATE']), 'conversation')
            ->create(['departure_date' => '2026-10-05']);
        $result = $this->answer($invalid, '2026-10-01');

        $this->assertSame('ASK_RETURN_DATE', $result['state']);
        $this->assertNull($invalid->refresh()->return_date);
    }

    public function test_multicity_completed_legs_keep_order_in_payload(): void
    {
        $flight = $this->completeFlight([
            'trip_type' => 'MULTI_CITY',
            'destination' => 'Morelia',
            'legs' => [
                ['origin' => 'Morelia', 'destination' => 'Monterrey', 'departure_date' => '2026-10-03', 'departure_time' => '12:00:00'],
                ['origin' => 'Monterrey', 'destination' => 'Cancún', 'departure_date' => '2026-10-04', 'departure_time' => '13:00:00'],
            ],
        ]);

        $payload = app(FlightApiService::class)->previewPayload($flight);

        $this->assertSame('multi_city', $payload['trip_type']);
        $this->assertSame(['Toluca', 'Morelia', 'Monterrey'], array_column($payload['legs'], 'origin'));
        $this->assertSame(['Morelia', 'Monterrey', 'Cancún'], array_column($payload['legs'], 'destination'));
    }

    public function test_corrections_update_only_target_field(): void
    {
        $this->travelTo(Carbon::parse('2026-09-21', config('whatsapp.timezone')));
        $flight = $this->completeFlight([
            'origin' => 'CDMX',
            'destination' => 'Mérida',
            'departure_date' => '2026-10-02',
            'passengers' => 4,
        ]);
        $snapshot = $flight->only(['departure_time', 'trip_type', 'client_name', 'client_email']);

        $this->answer($flight, 'somos 5 no 4');
        $this->answer($flight, 'mejor Cancún');
        $this->answer($flight, 'salgo de Toluca, no CDMX');
        $this->answer($flight, 'mejor el sábado');

        $flight->refresh();
        $this->assertSame(5, $flight->passengers);
        $this->assertSame('Cancún', $flight->destination);
        $this->assertSame('Toluca', $flight->origin);
        $this->assertSame('2026-09-26', $flight->departure_date->toDateString());
        foreach ($snapshot as $field => $value) {
            $this->assertEquals($value, $flight->{$field});
        }
    }

    public function test_one_way_with_return_date_adds_return_instead_of_ignoring_signal(): void
    {
        $flight = $this->completeFlight(['trip_type' => 'ONE_WAY']);

        $result = $this->answer($flight, 'regreso el sábado');

        $this->assertSame('ASK_RETURN_TIME', $result['state']);
        $this->assertSame('ROUND_TRIP', $flight->refresh()->trip_type);
        $this->assertSame('2026-10-03', $flight->return_date->toDateString());
        $this->assertStringContainsString('regresarían', $result['message']);
    }

    #[TestWith(['ASK_DEPARTURE_DATE', '2025-09-23', 'departure_date'])]
    #[TestWith(['ASK_DEPARTURE_DATE', '31 de febrero', 'departure_date'])]
    #[TestWith(['ASK_DEPARTURE_DATE', '2026-02-31', 'departure_date'])]
    #[TestWith(['ASK_DEPARTURE_TIME', '25:00', 'departure_time'])]
    #[TestWith(['ASK_DEPARTURE_TIME', '8:90', 'departure_time'])]
    #[TestWith(['ASK_DEPARTURE_TIME', 'a las 30', 'departure_time'])]
    public function test_invalid_dates_and_times_do_not_save_or_advance(string $state, string $input, string $field): void
    {
        $this->travelTo(Carbon::parse('2026-09-21', config('whatsapp.timezone')));
        $flight = WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()->state(['state' => $state]), 'conversation')
            ->create();

        $result = $this->answer($flight, $input);

        $this->assertSame($state, $result['state']);
        $this->assertNull($flight->refresh()->{$field});
    }

    #[TestWith(['cancelar', 'CANCELLED'])]
    #[TestWith(['empezar de nuevo', 'ASK_ORIGIN'])]
    #[TestWith(['quiero otro vuelo', 'ASK_ORIGIN'])]
    public function test_cancel_and_restart_commands_are_global(string $input, string $expectedState): void
    {
        $flight = $this->completeFlight();

        $result = $this->answer($flight, $input);

        $this->assertSame($expectedState, $result['state']);
        if ($expectedState === 'ASK_ORIGIN') {
            $this->assertNull($flight->refresh()->origin);
            $this->assertNull($flight->destination);
        }
    }

    public function test_human_handoff_stops_bot_and_return_to_bot_restores_state(): void
    {
        $flight = WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()->state(['state' => 'ASK_DESTINATION']), 'conversation')
            ->create(['origin' => 'Toluca']);

        $conversation = $flight->conversation()->firstOrFail();
        $result = app(WhatsAppChatbotService::class)->handleIncomingMessage($conversation, $flight, 'quiero un asesor');
        app(WhatsAppConversationService::class)->transferToHuman($conversation);

        $this->assertSame('TRANSFER_TO_HUMAN', $result['state']);
        $this->assertSame('', app(WhatsAppChatbotService::class)->handleIncomingMessage($conversation->refresh(), $flight->refresh(), 'Cancún')['message']);

        app(WhatsAppConversationService::class)->returnToBot($conversation->refresh());
        $result = $this->answer($flight, 'Cancún');

        $this->assertSame('ASK_DEPARTURE_DATE', $result['state']);
        $this->assertSame('Cancún', $flight->refresh()->destination);
    }

    #[TestWith([401])]
    #[TestWith([429])]
    #[TestWith([500])]
    #[TestWith([503])]
    public function test_meta_failures_do_not_mark_processed_or_duplicate_reply(int $status): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*/123/messages' => Http::response(['error' => ['message' => 'Meta failed']], $status)]);

        $this->webhook(['messages' => [$this->incomingMessage('in.meta-'.$status, 'Hola')]])->assertInternalServerError();
        $this->webhook(['messages' => [$this->incomingMessage('in.meta-'.$status, 'Hola')]])->assertInternalServerError();

        $inbound = WhatsAppMessage::query()->where('message_id', 'in.meta-'.$status)->sole();
        $this->assertNull($inbound->processed_at);
        $this->assertDatabaseMissing('whats_app_messages', ['direction' => 'outbound']);
        $this->assertSame('ASK_ORIGIN', $inbound->conversation->state);
    }

    public function test_summary_and_payload_match_and_exclude_legacy_fields(): void
    {
        $flight = $this->completeFlight([
            'luggage_count' => 9,
            'has_pets' => true,
            'ground_transport_required' => true,
            'wifi_required' => true,
            'catering_required' => true,
        ]);

        $summary = app(WhatsAppChatbotService::class)->summaryMessage($flight);
        $payload = app(FlightApiService::class)->previewPayload($flight);

        foreach (['Toluca', 'Cancún', '4 pasajeros'] as $expected) {
            $this->assertStringContainsString($expected, $summary);
        }
        $this->assertSame('Toluca', $payload['origin']);
        $this->assertSame('Cancún', $payload['destination']);
        $this->assertSame(4, $payload['passengers']);
        $this->assertArrayNotHasKey('luggage_count', $payload);
        $this->assertArrayNotHasKey('has_pets', $payload);
        $this->assertStringNotContainsString('equipaje', mb_strtolower($summary));
        $this->assertStringNotContainsString('mascotas', mb_strtolower($summary));
    }

    public function test_close_messages_keep_consistent_order_and_single_replies(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*/123/messages' => Http::sequence()
            ->push(['messages' => [['id' => 'out.origin']]])
            ->push(['messages' => [['id' => 'out.destination']]])]);
        WhatsAppConversation::factory()
            ->for(WhatsAppContact::factory()->state(['phone_number' => '5215512345678']), 'contact')
            ->create(['state' => 'ASK_ORIGIN']);

        $this->webhook(['messages' => [$this->incomingMessage('in.origin', 'Toluca')]])->assertOk();
        $this->webhook(['messages' => [$this->incomingMessage('in.destination', 'Cancún')]])->assertOk();
        $this->webhook(['messages' => [$this->incomingMessage('in.destination', 'Cancún')]])->assertOk();

        $flight = WhatsAppFlightRequest::query()->sole();
        $this->assertSame('Toluca', $flight->origin);
        $this->assertSame('Cancún', $flight->destination);
        $this->assertSame('ASK_DEPARTURE_DATE', $flight->conversation->state);
        Http::assertSentCount(2);
    }

    public function test_missing_request_and_unknown_state_recover_without_500(): void
    {
        $conversation = WhatsAppConversation::factory()->create(['state' => 'ASK_DESTINATION']);

        $request = app(WhatsAppConversationService::class)->findOrCreateFlightRequest($conversation);
        $result = app(WhatsAppChatbotService::class)->handleIncomingMessage($conversation, $request, 'Cancún');
        $this->assertSame('ASK_DEPARTURE_DATE', $result['state']);

        $conversation->update(['state' => 'LEGACY_STATE']);
        $result = app(WhatsAppChatbotService::class)->handleIncomingMessage($conversation->refresh(), $request->refresh(), 'continuar');
        $this->assertSame('ASK_ORIGIN', $result['state']);
    }

    public function test_natural_quote_intent_does_not_become_route(): void
    {
        $flight = WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()->state(['state' => 'START']), 'conversation')
            ->create();

        $result = $this->answer($flight, 'Hola, buenas tardes. Quiero cotizar un vuelo, pero todavía no tengo todo bien definido');

        $flight->refresh();
        $this->assertSame('ASK_ORIGIN', $result['state']);
        $this->assertNull($flight->origin);
        $this->assertNull($flight->destination);
    }

    public function test_quote_intent_with_general_temporal_hint_asks_origin_without_location_error(): void
    {
        Log::spy();
        $flight = WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()->state(['state' => 'ASK_ORIGIN']), 'conversation')
            ->create();

        $result = $this->answer($flight, 'Hola, necesito cotizar un vuelo para la próxima semana.');

        $this->assertSame('ASK_ORIGIN', $result['state']);
        $this->assertNull($flight->refresh()->origin);
        $this->assertNull($flight->destination);
        $this->assertNull($flight->conversation->refresh()->metadata['understanding_failures'] ?? null);
        $this->assertStringNotContainsString('No alcancé a identificar', $result['message']);
        $this->assertStringContainsString('Desde qué ciudad', $result['message']);
        Log::shouldHaveReceived('info')->with('WhatsApp chatbot engine decision.', \Mockery::on(
            fn (array $context): bool => ($context['intent'] ?? null) === 'FLIGHT_QUOTE'
        ));
    }

    public function test_plain_quote_intent_asks_origin_without_location_error(): void
    {
        $flight = WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()->state(['state' => 'ASK_ORIGIN']), 'conversation')
            ->create();

        $result = $this->answer($flight, 'Quiero cotizar un vuelo.');

        $this->assertSame('ASK_ORIGIN', $result['state']);
        $this->assertNull($flight->refresh()->origin);
        $this->assertNull($flight->conversation->refresh()->metadata['understanding_failures'] ?? null);
        $this->assertStringNotContainsString('No alcancé a identificar', $result['message']);
        $this->assertStringContainsString('Desde qué ciudad', $result['message']);
    }

    public function test_quote_intent_with_concrete_date_asks_origin_without_location_error(): void
    {
        $this->travelTo(Carbon::parse('2026-09-22', config('whatsapp.timezone')));
        $flight = WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()->state(['state' => 'ASK_ORIGIN']), 'conversation')
            ->create();

        $result = $this->answer($flight, 'Necesito un vuelo mañana.');

        $this->assertSame('ASK_ORIGIN', $result['state']);
        $this->assertNull($flight->refresh()->origin);
        $this->assertNull($flight->conversation->refresh()->metadata['understanding_failures'] ?? null);
        $this->assertStringNotContainsString('No alcancé a identificar', $result['message']);
        $this->assertStringContainsString('Desde qué ciudad', $result['message']);
    }

    public function test_invalid_direct_origin_answer_increments_understanding_failure(): void
    {
        $flight = WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()->state(['state' => 'ASK_ORIGIN']), 'conversation')
            ->create();

        $result = $this->answer($flight, 'Quién sabe');

        $this->assertSame('ASK_ORIGIN', $result['state']);
        $this->assertNull($flight->refresh()->origin);
        $this->assertSame(1, $flight->conversation->refresh()->metadata['understanding_failures']['ASK_ORIGIN']);
        $this->assertStringContainsString('No alcancé a identificar', $result['message']);
    }

    public function test_natural_origin_answer_with_saldríamos_saves_origin_without_fallback(): void
    {
        $flight = WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()->state(['state' => 'ASK_ORIGIN']), 'conversation')
            ->create();

        $result = $this->answer($flight, 'Saldríamos de Toluca.');

        $this->assertSame('ASK_DESTINATION', $result['state']);
        $this->assertSame('Toluca', $flight->refresh()->origin);
        $this->assertNull($flight->conversation->refresh()->metadata['understanding_failures'] ?? null);
        $this->assertStringNotContainsString('No alcancé a identificar', $result['message']);
    }

    public function test_origin_with_alternate_origin_does_not_become_destination(): void
    {
        $flight = WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()->state(['state' => 'ASK_ORIGIN']), 'conversation')
            ->create();

        $result = $this->answer($flight, 'Saldríamos de Toluca, aunque también podría ser desde CDMX si sale mejor.');

        $flight->refresh();
        $this->assertSame('ASK_DESTINATION', $result['state']);
        $this->assertSame('Toluca', $flight->origin);
        $this->assertNull($flight->destination);
        $this->assertStringContainsString('CDMX', $result['message']);
    }

    public function test_uncertain_stopover_asks_before_creating_multicity(): void
    {
        $flight = WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()->state(['state' => 'ASK_DESTINATION']), 'conversation')
            ->create(['origin' => 'Toluca']);

        $result = $this->answer($flight, 'Queremos ir a Cancún, pero antes posiblemente pasar por Monterrey.');

        $flight->refresh();
        $this->assertSame('ASK_DESTINATION', $result['state']);
        $this->assertNull($flight->destination);
        $this->assertNull($flight->trip_type);
        $this->assertStringContainsString('Toluca → Monterrey → Cancún', $result['message']);
    }

    public function test_contextual_yes_confirms_pending_multicity_route_without_using_yes_as_location(): void
    {
        $conversation = WhatsAppConversation::factory()->create([
            'state' => 'ASK_DESTINATION',
            'metadata' => [
                'pending_route_confirmation' => [
                    'origin' => 'Toluca',
                    'stop' => 'Monterrey',
                    'destination' => 'Cancún',
                ],
            ],
        ]);
        $flight = WhatsAppFlightRequest::factory()->for($conversation, 'conversation')->create(['origin' => 'Toluca']);

        $result = $this->answer($flight, 'Sí, primero Monterrey y después Cancún.');

        $flight->refresh();
        $this->assertSame('ASK_DEPARTURE_DATE', $result['state']);
        $this->assertSame('Toluca', $flight->origin);
        $this->assertSame('Monterrey', $flight->destination);
        $this->assertSame('MULTI_CITY', $flight->trip_type);
        $this->assertSame('Cancún', $flight->legs[0]['destination']);
        $this->assertStringNotContainsString('Sí →', $result['message']);
    }

    public function test_natural_date_with_time_options_saves_date_and_asks_time_choice(): void
    {
        $this->travelTo(Carbon::parse('2026-09-22', config('whatsapp.timezone')));
        $flight = WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()->state(['state' => 'ASK_DEPARTURE_DATE']), 'conversation')
            ->create();

        $result = $this->answer($flight, 'Este viernes como a las 7 u 8 de la noche.');

        $this->assertSame('ASK_DEPARTURE_TIME', $result['state']);
        $this->assertSame('2026-09-25', $flight->refresh()->departure_date->toDateString());
        $this->assertNull($flight->departure_time);
        $this->assertStringContainsString('7:00 p. m.', $result['message']);
        $this->assertStringContainsString('8:00 p. m.', $result['message']);
    }

    public function test_better_night_time_updates_departure_time_not_destination(): void
    {
        $flight = WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()->state(['state' => 'ASK_DEPARTURE_TIME']), 'conversation')
            ->create([
                'origin' => 'Toluca',
                'destination' => 'Cancún',
                'departure_date' => '2026-09-25',
            ]);

        $result = $this->answer($flight, 'Mejor 8 de la noche.');

        $flight->refresh();
        $this->assertSame('ASK_TRIP_TYPE', $result['state']);
        $this->assertSame('20:00:00', $flight->departure_time);
        $this->assertSame('Cancún', $flight->destination);
    }

    public function test_adults_and_children_are_summed_as_passengers(): void
    {
        $flight = WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()->state(['state' => 'ASK_PASSENGERS']), 'conversation')
            ->create();

        $result = $this->answer($flight, 'Somos 6 adultos y 2 niños.');

        $this->assertNotSame('ASK_PASSENGERS', $result['state']);
        $this->assertSame(8, $flight->refresh()->passengers);
    }

    public function test_return_signal_is_understood_even_when_state_expects_departure_time(): void
    {
        $this->travelTo(Carbon::parse('2026-09-22', config('whatsapp.timezone')));
        $flight = WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()->state(['state' => 'ASK_DEPARTURE_TIME']), 'conversation')
            ->create([
                'origin' => 'Toluca',
                'destination' => 'Cancún',
                'departure_date' => '2026-09-25',
                'departure_time' => '20:00:00',
            ]);

        $result = $this->answer($flight, 'Y vamos a regresar el domingo.');

        $flight->refresh();
        $this->assertSame('ASK_RETURN_TIME', $result['state']);
        $this->assertSame('ROUND_TRIP', $flight->trip_type);
        $this->assertSame('2026-09-27', $flight->return_date->toDateString());
        $this->assertStringNotContainsString('No entendí la hora', $result['message']);
    }

    #[DataProvider('naturalCompleteRouteProvider')]
    public function test_natural_complete_routes_are_extracted_before_origin_fallback(string $input, string $origin, string $destination): void
    {
        $flight = WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()->state(['state' => 'ASK_ORIGIN']), 'conversation')
            ->create();

        $result = $this->answer($flight, $input);

        $flight->refresh();
        $this->assertSame($origin, $flight->origin);
        $this->assertSame($destination, $flight->destination);
        $this->assertNotSame('ASK_ORIGIN', $result['state']);
        $this->assertStringContainsString("{$origin} → {$destination}", $result['message']);
    }

    /** @return array<string, array{input:string, origin:string, destination:string}> */
    public static function naturalCompleteRouteProvider(): array
    {
        $faker = Factory::create('es_MX');
        $faker->seed(20260923);

        $templates = [
            'salida_seria' => 'La salida sería de %s a %s.',
            'quiero_salir' => 'Quiero salir de %s a %s',
            'desde_hasta' => 'Desde %s hasta %s',
        ];

        $cases = [];
        foreach ($templates as $templateName => $template) {
            for ($routeIndex = 1; $routeIndex <= 3; $routeIndex++) {
                $route = [
                    'origin' => $faker->unique()->city(),
                    'destination' => $faker->unique()->city(),
                ];

                $cases[$templateName.'_route_'.$routeIndex] = [
                    'input' => sprintf($template, $route['origin'], $route['destination']),
                    'origin' => $route['origin'],
                    'destination' => $route['destination'],
                ];
            }
        }

        return $cases;
    }

    public function test_complete_route_after_out_of_scope_starts_clean_quote_without_yes(): void
    {
        $conversation = WhatsAppConversation::factory()->create([
            'state' => 'ASK_ORIGIN',
            'metadata' => ['last_bot_intent' => 'unsupported_offer'],
        ]);
        $flight = WhatsAppFlightRequest::factory()->for($conversation, 'conversation')->create([
            'origin' => 'Viejo origen',
            'destination' => 'Viejo destino',
            'departure_date' => '2026-10-02',
            'passengers' => 4,
            'trip_type' => 'ONE_WAY',
        ]);

        $result = $this->answer($flight, 'La salida sería de Toluca a Cancún');

        $newFlight = $conversation->refresh()->flightRequest()->firstOrFail();
        $this->assertSame('ASK_DEPARTURE_DATE', $result['state']);
        $this->assertNull($conversation->metadata);
        $this->assertSame('Toluca', $newFlight->origin);
        $this->assertSame('Cancún', $newFlight->destination);
        $this->assertNull($newFlight->departure_date);
        $this->assertNull($newFlight->passengers);
    }

    public function test_natural_date_after_complete_route_does_not_ask_origin_again(): void
    {
        $this->travelTo(Carbon::parse('2026-09-22', config('whatsapp.timezone')));
        $flight = WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()->state(['state' => 'ASK_ORIGIN']), 'conversation')
            ->create();

        $this->answer($flight, 'La salida sería de Toluca a Cancún.');
        $result = $this->answer($flight, 'Sería este viernes.');

        $flight->refresh();
        $this->assertSame('2026-09-25', $flight->departure_date->toDateString());
        $this->assertSame('ASK_DEPARTURE_TIME', $result['state']);
        $this->assertStringNotContainsString('origen', mb_strtolower($result['message']));
    }

    public function test_global_interpretation_extracts_multiple_quote_details_before_state_fallback(): void
    {
        $this->travelTo(Carbon::parse('2026-09-22', config('whatsapp.timezone')));
        $flight = WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()->state(['state' => 'ASK_ORIGIN']), 'conversation')
            ->create();

        $result = $this->answer($flight, 'Hola, somos 5, salimos de Toluca a Cancún este viernes a las 8 de la noche, solo ida.');

        $flight->refresh();
        $this->assertSame('Toluca', $flight->origin);
        $this->assertSame('Cancún', $flight->destination);
        $this->assertSame('2026-09-25', $flight->departure_date->toDateString());
        $this->assertSame('20:00:00', $flight->departure_time);
        $this->assertSame(5, $flight->passengers);
        $this->assertSame('ONE_WAY', $flight->trip_type);
        $this->assertSame('ASK_AIRCRAFT_PREFERENCE', $result['state']);
    }

    public function test_user_question_does_not_persist_as_contextual_answer(): void
    {
        $flight = WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()->state(['state' => 'ASK_ORIGIN']), 'conversation')
            ->create();

        $result = $this->answer($flight, '¿Qué información necesitas?');

        $this->assertSame('ASK_ORIGIN', $result['state']);
        $this->assertNull($flight->refresh()->origin);
        $this->assertStringContainsString('Desde qué ciudad', $result['message']);
    }

    public function test_date_and_time_are_applied_together_before_time_fallback(): void
    {
        $this->travelTo(Carbon::parse('2026-09-22', config('whatsapp.timezone')));
        $flight = WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()->state(['state' => 'ASK_DEPARTURE_DATE']), 'conversation')
            ->create([
                'origin' => 'Toluca',
                'destination' => 'Cancún',
            ]);

        $result = $this->answer($flight, 'Este viernes a las 8 de la noche');

        $flight->refresh();
        $this->assertSame('2026-09-25', $flight->departure_date->toDateString());
        $this->assertSame('20:00:00', $flight->departure_time);
        $this->assertSame('ASK_TRIP_TYPE', $result['state']);
    }

    public function test_multicity_sequence_preserves_existing_origin(): void
    {
        $flight = WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()->state(['state' => 'ASK_DESTINATION']), 'conversation')
            ->create(['origin' => 'Toluca']);

        $result = $this->answer($flight, 'Primero Morelia, luego Monterrey y al final Cancún.');

        $flight->refresh();
        $this->assertSame('Toluca', $flight->origin);
        $this->assertSame('Morelia', $flight->destination);
        $this->assertSame('MULTI_CITY', $flight->trip_type);
        $this->assertSame('Monterrey', $flight->legs[0]['destination']);
        $this->assertSame('Cancún', $flight->legs[1]['destination']);
        $this->assertStringContainsString('Toluca → Morelia → Monterrey → Cancún', $result['message']);
    }

    public function test_flow_finishes_after_last_required_contact_field_without_optional_loop(): void
    {
        $flight = $this->completeFlight([
            'aircraft_preference' => 'sin preferencia',
            'other_services' => 'ninguno',
            'notes' => 'sin notas',
            'client_email' => null,
        ]);
        $flight->conversation()->update(['state' => 'ASK_EMAIL']);

        $result = $this->answer($flight, 'juan@example.com');

        $this->assertSame('SHOW_SUMMARY', $result['state']);
        $this->assertSame('juan@example.com', $flight->refresh()->client_email);
        $this->assertStringContainsString('¿Todo está correcto para solicitar la cotización?', $result['message']);
        $this->assertStringNotContainsString('empresa', mb_strtolower($result['message']));
        $this->assertStringNotContainsString('presupuesto', mb_strtolower($result['message']));
    }

    public function test_autopilot_resume_quote_continues_from_next_missing_field_without_resetting(): void
    {
        $flight = WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()->state(['state' => 'START']), 'conversation')
            ->create([
                'origin' => 'Toluca',
                'destination' => 'Cancún',
                'departure_date' => '2026-10-02',
            ]);

        $result = $this->answer($flight, 'Seguimos con el vuelo');

        $flight->refresh();
        $this->assertSame('ASK_DEPARTURE_TIME', $result['state']);
        $this->assertSame('Toluca', $flight->origin);
        $this->assertSame('Cancún', $flight->destination);
        $this->assertStringContainsString('retomemos', mb_strtolower($result['message']));
        $this->assertStringContainsString('hora', mb_strtolower($result['message']));
    }

    public function test_autopilot_quote_readiness_does_not_depend_on_current_state(): void
    {
        $service = app(WhatsAppChatbotService::class);
        $ready = $this->completeFlight([
            'aircraft_preference' => 'sin preferencia',
            'other_services' => 'ninguno',
            'notes' => 'sin notas',
        ]);
        $ready->conversation()->update(['state' => 'ASK_ORIGIN']);

        $incomplete = $this->completeFlight([
            'aircraft_preference' => 'sin preferencia',
            'other_services' => 'ninguno',
            'notes' => 'sin notas',
            'client_email' => null,
        ]);

        $this->assertTrue($service->isQuoteReady($ready->refresh()));
        $this->assertFalse($service->isQuoteReady($incomplete->refresh()));
    }

    public function test_autopilot_understanding_failures_offer_human_without_destroying_request(): void
    {
        $flight = WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()->state(['state' => 'ASK_EMAIL']), 'conversation')
            ->create([
                'origin' => 'Toluca',
                'destination' => 'Cancún',
            ]);

        $this->answer($flight, 'correo invalido');
        $this->answer($flight, 'sigue mal');
        $result = $this->answer($flight, 'tampoco es correo');

        $flight->refresh();
        $this->assertSame('ASK_EMAIL', $result['state']);
        $this->assertSame('Toluca', $flight->origin);
        $this->assertSame('Cancún', $flight->destination);
        $this->assertNull($flight->client_email);
        $this->assertStringContainsString('asesor', mb_strtolower($result['message']));
        $this->assertSame(3, $flight->conversation->refresh()->metadata['understanding_failures']['ASK_EMAIL']);
    }

    public function test_autopilot_relative_return_date_uses_departure_date_context(): void
    {
        $flight = WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()->state(['state' => 'ASK_RETURN_DATE']), 'conversation')
            ->create([
                'origin' => 'Toluca',
                'destination' => 'Cancún',
                'departure_date' => '2026-10-02',
                'departure_time' => '15:00:00',
                'trip_type' => 'ROUND_TRIP',
            ]);

        $result = $this->answer($flight, 'al día siguiente');

        $this->assertSame('2026-10-03', $flight->refresh()->return_date->toDateString());
        $this->assertSame('ASK_RETURN_TIME', $result['state']);
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
            'aircraft_preference' => null,
            'other_services' => null,
            'client_name' => 'Juan Pérez',
            'client_email' => 'juan@example.com',
            ...$attributes,
        ]);
    }

    /** @param array<string, mixed> $value */
    private function webhook(array $value): TestResponse
    {
        config([
            'queue.default' => 'sync',
            'services.whatsapp.app_secret' => 'test-secret',
            'services.whatsapp.phone_number_id' => '123',
            'services.whatsapp.access_token' => 'test-token',
        ]);

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'contacts' => [['wa_id' => '5215512345678', 'profile' => ['name' => 'Kevin']]],
                        ...$value,
                    ],
                ]],
            ]],
        ];

        return $this->postJson('/api/webhooks/whatsapp', $payload, [
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', json_encode($payload), 'test-secret'),
        ]);
    }

    /** @return array{id:string,from:string,timestamp:string,type:string,text:array{body:string}} */
    private function incomingMessage(string $id, string $body): array
    {
        return [
            'id' => $id,
            'from' => '5215512345678',
            'timestamp' => '1790000000',
            'type' => 'text',
            'text' => ['body' => $body],
        ];
    }
}
