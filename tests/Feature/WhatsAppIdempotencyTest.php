<?php

namespace Tests\Feature;

use App\Models\WhatsAppContact;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppFlightRequest;
use App\Models\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class WhatsAppIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'queue.default' => 'sync',
            'services.whatsapp.app_secret' => 'test-secret',
            'services.whatsapp.phone_number_id' => '123',
            'services.whatsapp.access_token' => 'test-token',
        ]);
    }

    public function test_duplicate_signed_webhook_sends_one_reply_and_applies_input_once(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*/123/messages' => Http::response(['messages' => [['id' => 'out.once']]])]);
        Log::spy();

        $this->webhook()->assertOk()->assertJson(['received' => true]);
        $this->webhook()->assertOk()->assertJson(['received' => true]);

        $this->assertDatabaseCount('whats_app_messages', 2);
        $this->assertSame('ASK_ORIGIN', WhatsAppConversation::query()->sole()->state);
        $this->assertNull(WhatsAppFlightRequest::query()->sole()->origin);
        $this->assertNotNull(WhatsAppMessage::query()->where('message_id', 'in.once')->sole()->processed_at);
        Http::assertSentCount(1);
        Log::shouldHaveReceived('info')->with('WhatsApp inbound processing.', \Mockery::on(fn (array $context): bool => $context['incoming_message_id'] === 'in.once'
            && $context['conversation_id'] === WhatsAppConversation::query()->sole()->id
            && $context['state'] === 'ASK_ORIGIN'
            && $context['action'] === 'skip_processed'
        ))->once();
    }

    public function test_status_only_webhooks_update_delivery_without_running_bot(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*/123/messages' => Http::response([])]);
        $outbound = WhatsAppMessage::factory()->create(['message_id' => 'out.once', 'direction' => 'outbound']);
        $conversation = $outbound->conversation;
        $initialState = $conversation->state;

        foreach (['sent', 'delivered', 'read', 'read'] as $status) {
            $this->webhook(['statuses' => [['id' => 'out.once', 'status' => $status, 'timestamp' => '1790000000']]])->assertOk();
        }

        $this->assertSame('read', $outbound->refresh()->status);
        $this->assertSame($initialState, $conversation->refresh()->state);
        $this->assertDatabaseCount('whats_app_messages', 1);
        $this->assertDatabaseCount('whats_app_flight_requests', 0);
        Http::assertNothingSent();
    }

    public function test_mixed_webhook_and_duplicate_messages_do_not_duplicate_replies(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*/123/messages' => Http::response(['messages' => [['id' => 'out.once']]])]);
        $value = [
            'messages' => [$this->incomingMessage(), $this->incomingMessage()],
            'statuses' => [['id' => 'out.once', 'status' => 'read', 'timestamp' => '1790000000']],
        ];

        $this->webhook($value)->assertOk();
        $this->webhook($value)->assertOk();

        $this->assertDatabaseCount('whats_app_messages', 2);
        $this->assertDatabaseHas('whats_app_messages', ['message_id' => 'out.once', 'status' => 'read']);
        Http::assertSentCount(1);
    }

    public function test_retry_after_outbound_storage_failure_does_not_resend_accepted_reply(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*/123/messages' => Http::response(['messages' => [['id' => 'out.once']]])]);
        $failNextOutbound = true;
        WhatsAppMessage::creating(function (WhatsAppMessage $message) use (&$failNextOutbound): void {
            if ($message->direction === 'outbound' && $failNextOutbound) {
                $failNextOutbound = false;
                throw new RuntimeException('Outbound storage unavailable.');
            }
        });

        $this->webhook()->assertInternalServerError();
        $this->assertDatabaseCount('whats_app_messages', 1);
        $this->webhook()->assertOk();
        $this->webhook()->assertOk();

        $this->assertDatabaseCount('whats_app_messages', 2);
        $this->assertNotNull(WhatsAppMessage::query()->where('message_id', 'in.once')->sole()->processed_at);
        $this->assertNull(WhatsAppFlightRequest::query()->sole()->origin);
        Http::assertSentCount(1);
    }

    public function test_timeout_does_not_automatically_resend_an_uncertain_reply(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*/123/messages' => Http::failedConnection()]);

        $this->webhook()->assertInternalServerError();

        Http::fake(['https://graph.facebook.com/*/123/messages' => Http::response(['messages' => [['id' => 'out.duplicate']]])]);
        $this->webhook()->assertInternalServerError();

        $this->assertDatabaseCount('whats_app_messages', 1);
        $this->assertDatabaseHas('whats_app_messages', ['message_id' => 'in.once', 'processed_at' => null]);
        $this->assertSame('ASK_ORIGIN', WhatsAppConversation::query()->sole()->state);
        Http::assertNothingSent();
    }

    public function test_retry_after_receipt_storage_failure_does_not_resend_reply(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*/123/messages' => Http::response(['messages' => [['id' => 'out.once']]])]);
        WhatsAppMessage::updating(function (WhatsAppMessage $message): void {
            if (isset($message->processing_context['sent_response'])) {
                throw new RuntimeException('Receipt storage unavailable.');
            }
        });

        $this->webhook()->assertInternalServerError();
        $this->webhook()->assertInternalServerError();

        $this->assertDatabaseCount('whats_app_messages', 1);
        $this->assertDatabaseHas('whats_app_messages', ['message_id' => 'in.once', 'processed_at' => null]);
        Http::assertSentCount(1);
    }

    #[TestWith([200])]
    #[TestWith([500])]
    #[TestWith([502])]
    public function test_response_without_message_id_or_explicit_rejection_is_not_automatically_resent(int $status): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*/123/messages' => Http::response([], $status)]);

        $this->webhook()->assertInternalServerError();
        $this->webhook()->assertInternalServerError();

        $this->assertDatabaseCount('whats_app_messages', 1);
        Http::assertSentCount(1);
    }

    #[TestWith([400])]
    #[TestWith([500])]
    public function test_rejected_reply_can_be_retried_without_applying_input_again(int $status): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*/123/messages' => Http::sequence()
            ->push(['error' => ['message' => 'Message rejected']], $status)
            ->push(['messages' => [['id' => 'out.once']]])]);

        $this->webhook()->assertInternalServerError();
        $this->webhook()->assertOk();
        $this->webhook()->assertOk();

        $this->assertDatabaseCount('whats_app_messages', 2);
        $this->assertNull(WhatsAppFlightRequest::query()->sole()->origin);
        $this->assertNotNull(WhatsAppMessage::query()->where('message_id', 'in.once')->sole()->processed_at);
        Http::assertSentCount(2);
    }

    public function test_missing_credentials_can_be_fixed_and_pending_reply_retried(): void
    {
        config(['services.whatsapp.access_token' => '', 'whatsapp.access_token' => '']);
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*/123/messages' => Http::response(['messages' => [['id' => 'out.once']]])]);

        $this->webhook()->assertInternalServerError();
        config(['services.whatsapp.access_token' => 'test-token']);
        $this->webhook()->assertOk();

        $this->assertDatabaseCount('whats_app_messages', 2);
        $this->assertNull(WhatsAppFlightRequest::query()->sole()->origin);
        Http::assertSentCount(1);
    }

    public function test_retry_after_completion_storage_failure_does_not_resend_reply(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*/123/messages' => Http::response(['messages' => [['id' => 'out.once']]])]);
        $failCompletion = true;
        WhatsAppMessage::updating(function (WhatsAppMessage $message) use (&$failCompletion): void {
            if ($message->direction === 'inbound' && $message->processed_at && $failCompletion) {
                $failCompletion = false;
                throw new RuntimeException('Completion storage unavailable.');
            }
        });

        $this->webhook()->assertInternalServerError();
        $this->webhook()->assertOk();

        $this->assertDatabaseCount('whats_app_messages', 2);
        $this->assertNotNull(WhatsAppMessage::query()->where('message_id', 'in.once')->sole()->processed_at);
        Http::assertSentCount(1);
    }

    public function test_each_automated_stage_requires_a_new_message_and_retries_do_not_repeat_it(): void
    {
        config(['flight_api.base_url' => 'https://backend.test', 'flight_api.retry_times' => 0]);
        Http::preventStrayRequests();
        Http::fake([
            'https://graph.facebook.com/*/123/messages' => Http::sequence()
                ->push(['messages' => [['id' => 'out.search']]])
                ->push(['messages' => [['id' => 'out.options']]]),
            'https://backend.test/api/v1/client/quotes/preview' => Http::response(['options' => [['aircraft_id' => 101, 'aircraft_name' => 'Jet']]]),
        ]);
        $conversation = WhatsAppConversation::factory()
            ->for(WhatsAppContact::factory()->state(['phone_number' => '5215512345678']), 'contact')
            ->create(['state' => 'SEARCH_FLIGHTS']);
        WhatsAppFlightRequest::factory()->for($conversation, 'conversation')->create([
            'origin' => 'Toluca', 'destination' => 'Cancún', 'departure_date' => '2026-10-02',
            'departure_time' => '14:00:00', 'passengers' => 4, 'trip_type' => 'ONE_WAY',
            'status' => 'confirmed', 'confirmed_at' => now(),
        ]);
        $failOptions = true;
        WhatsAppMessage::creating(function (WhatsAppMessage $message) use (&$failOptions): void {
            if ($message->message_id === 'out.options' && $failOptions) {
                $failOptions = false;
                throw new RuntimeException('Options storage unavailable.');
            }
        });

        $search = ['messages' => [[...$this->incomingMessage(), 'text' => ['body' => 'continuar']]]];
        $this->webhook($search)->assertOk();
        $this->webhook($search)->assertOk();
        $this->assertSame('SHOW_RESULTS', $conversation->refresh()->state);
        Http::assertSentCount(2);

        $next = ['messages' => [[...$this->incomingMessage(), 'id' => 'in.options', 'text' => ['body' => 'continuar']]]];
        $this->webhook($next)->assertInternalServerError();
        $this->webhook($next)->assertOk();
        $this->webhook($next)->assertOk();

        $this->assertDatabaseCount('whats_app_messages', 4);
        $this->assertSame('SELECT_AIRCRAFT', $conversation->refresh()->state);
        $this->assertNull($conversation->flightRequest->selected_aircraft_id);
        Http::assertSentCount(3);
    }

    #[TestWith(['Guadalajara', 'Mérida'])]
    #[TestWith(['MMTO', 'MMUN'])]
    #[TestWith(['Monterrey', 'Los Cabos'])]
    #[TestWith(['Madrid', 'KTEB'])]
    public function test_routes_use_real_answers_and_duplicates_never_fill_the_next_field(string $origin, string $destination): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*/123/messages' => Http::sequence()
            ->push(['messages' => [['id' => 'out.origin']]])
            ->push(['messages' => [['id' => 'out.destination']]])]);
        $conversation = WhatsAppConversation::factory()
            ->for(WhatsAppContact::factory()->state(['phone_number' => '5215512345678']), 'contact')
            ->create(['state' => 'ASK_ORIGIN']);
        $originMessage = ['messages' => [[...$this->incomingMessage(), 'id' => 'in.origin', 'text' => ['body' => '  '.$origin.'  ']]]];
        $destinationMessage = ['messages' => [[...$this->incomingMessage(), 'id' => 'in.destination', 'text' => ['body' => $destination]]]];

        $this->webhook($originMessage)->assertOk();
        $this->webhook($originMessage)->assertOk();

        $flight = $conversation->flightRequest()->sole();
        $this->assertSame($origin, $flight->origin);
        $this->assertNull($flight->destination);
        $this->assertSame('ASK_DESTINATION', $conversation->refresh()->state);
        $this->assertDatabaseHas('whats_app_messages', ['message_id' => 'out.origin', 'body' => "Perfecto, saliendo de {$origin}. ¿A dónde te gustaría volar?"]);

        $this->webhook($destinationMessage)->assertOk();
        $this->webhook($originMessage)->assertOk();
        $this->webhook($destinationMessage)->assertOk();

        $this->assertSame($destination, $flight->refresh()->destination);
        $this->assertNull($flight->departure_date);
        $this->assertSame('ASK_DEPARTURE_DATE', $conversation->refresh()->state);
        $this->assertDatabaseCount('whats_app_flight_requests', 1);
        $this->assertDatabaseCount('whats_app_messages', 4);
        $this->assertSame(0, $conversation->messages()->where('direction', 'inbound')->whereNull('processed_at')->count());
        Http::assertSentCount(2);
    }

    public function test_rejected_origin_reply_resumes_without_using_origin_as_destination(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*/123/messages' => Http::sequence()
            ->push(['error' => ['message' => 'Rejected']], 400)
            ->push(['messages' => [['id' => 'out.origin']]])]);
        $conversation = WhatsAppConversation::factory()
            ->for(WhatsAppContact::factory()->state(['phone_number' => '5215512345678']), 'contact')
            ->create(['state' => 'ASK_ORIGIN']);
        $message = ['messages' => [[...$this->incomingMessage(), 'text' => ['body' => 'Guadalajara']]]];

        $this->webhook($message)->assertInternalServerError();

        $inbound = WhatsAppMessage::query()->where('message_id', 'in.once')->sole();
        $this->assertNull($inbound->processed_at);
        $this->assertSame('ASK_ORIGIN', $inbound->processing_context['state_before']);
        $this->assertSame('ASK_DESTINATION', $conversation->refresh()->state);

        $this->webhook($message)->assertOk();
        $this->webhook($message)->assertOk();

        $flight = $conversation->flightRequest()->sole();
        $this->assertSame('Guadalajara', $flight->origin);
        $this->assertNull($flight->destination);
        $this->assertNotNull($inbound->refresh()->processed_at);
        $this->assertDatabaseCount('whats_app_messages', 2);
        Http::assertSentCount(2);
    }

    public function test_hola_restart_clears_current_request_and_does_not_process_older_pending_inbounds(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*/123/messages' => Http::response(['messages' => [['id' => 'out.hola']]])]);
        $conversation = WhatsAppConversation::factory()
            ->for(WhatsAppContact::factory()->state(['phone_number' => '5215512345678']), 'contact')
            ->create(['state' => 'START']);
        WhatsAppFlightRequest::factory()->for($conversation, 'conversation')->create([
            'origin' => 'Toluca',
            'destination' => 'Cancún',
            'departure_date' => '2026-10-02',
            'departure_time' => '14:00:00',
            'passengers' => 4,
            'trip_type' => 'ONE_WAY',
            'status' => 'confirmed',
            'confirmed_at' => now(),
        ]);
        $staleInbound = WhatsAppMessage::factory()->for($conversation, 'conversation')->create([
            'message_id' => 'in.stale',
            'direction' => 'inbound',
            'body' => 'Cancún',
            'processed_at' => null,
        ]);

        $this->webhook(['messages' => [[...$this->incomingMessage(), 'id' => 'in.hola', 'text' => ['body' => 'Hola']]]])->assertOk();

        $flight = $conversation->flightRequest()->sole();
        $this->assertSame('ASK_ORIGIN', $conversation->refresh()->state);
        $this->assertNull($flight->refresh()->origin);
        $this->assertNull($flight->destination);
        $this->assertNull($flight->departure_date);
        $this->assertNull($staleInbound->refresh()->processed_at);
        $this->assertDatabaseHas('whats_app_messages', [
            'message_id' => 'out.hola',
            'direction' => 'outbound',
            'body' => "¡Hola! Bienvenido a Sky Group Aviation ✈️\n¿Desde qué ciudad o aeropuerto deseas salir?",
        ]);
        $this->assertDatabaseMissing('whats_app_messages', ['body' => '¿Cuál es el destino?']);
        Http::assertSentCount(1);
    }

    public function test_past_departure_date_gets_specific_message_without_leaving_date_state(): void
    {
        $this->travelTo(Carbon::parse('2026-09-21', config('whatsapp.timezone')));
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*/123/messages' => Http::response(['messages' => [['id' => 'out.past-date']]])]);
        $conversation = WhatsAppConversation::factory()
            ->for(WhatsAppContact::factory()->state(['phone_number' => '5215512345678']), 'contact')
            ->create(['state' => 'ASK_DEPARTURE_DATE']);
        WhatsAppFlightRequest::factory()->for($conversation, 'conversation')->create([
            'origin' => 'Querétaro',
            'destination' => 'Cancún',
        ]);

        $this->webhook(['messages' => [[...$this->incomingMessage(), 'id' => 'in.past-date', 'text' => ['body' => '2026-04-23']]]])->assertOk();

        $flight = $conversation->flightRequest()->sole();
        $this->assertSame('ASK_DEPARTURE_DATE', $conversation->refresh()->state);
        $this->assertNull($flight->refresh()->departure_date);
        $this->assertDatabaseHas('whats_app_messages', [
            'message_id' => 'out.past-date',
            'body' => "Esa fecha ya pasó. ¿Qué otra fecha tienes en mente?\nPerfecto, Querétaro → Cancún. ¿Para qué día tienes pensado viajar?",
        ]);
    }

    public function test_future_departure_date_is_accepted_and_advances_once(): void
    {
        $this->travelTo(Carbon::parse('2026-09-21', config('whatsapp.timezone')));
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*/123/messages' => Http::response(['messages' => [['id' => 'out.future-date']]])]);
        $conversation = WhatsAppConversation::factory()
            ->for(WhatsAppContact::factory()->state(['phone_number' => '5215512345678']), 'contact')
            ->create(['state' => 'ASK_DEPARTURE_DATE']);
        WhatsAppFlightRequest::factory()->for($conversation, 'conversation')->create([
            'origin' => 'Querétaro',
            'destination' => 'Cancún',
        ]);

        $this->webhook(['messages' => [[...$this->incomingMessage(), 'id' => 'in.future-date', 'text' => ['body' => '2026-10-02']]]])->assertOk();

        $flight = $conversation->flightRequest()->sole();
        $this->assertSame('ASK_DEPARTURE_TIME', $conversation->refresh()->state);
        $this->assertSame('2026-10-02', $flight->refresh()->departure_date->toDateString());
        $this->assertDatabaseHas('whats_app_messages', [
            'message_id' => 'out.future-date',
            'body' => '¿A qué hora te gustaría salir?',
        ]);
    }

    public function test_help_message_keeps_current_state_and_does_not_change_request(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*/123/messages' => Http::response(['messages' => [['id' => 'out.help']]])]);
        $conversation = WhatsAppConversation::factory()
            ->for(WhatsAppContact::factory()->state(['phone_number' => '5215512345678']), 'contact')
            ->create(['state' => 'ASK_DEPARTURE_DATE']);
        WhatsAppFlightRequest::factory()->for($conversation, 'conversation')->create([
            'origin' => 'Querétaro',
            'destination' => 'Cancún',
        ]);

        $this->webhook(['messages' => [[...$this->incomingMessage(), 'id' => 'in.help', 'text' => ['body' => 'no entiendo']]]])->assertOk();

        $flight = $conversation->flightRequest()->sole();
        $this->assertSame('ASK_DEPARTURE_DATE', $conversation->refresh()->state);
        $this->assertNull($flight->refresh()->departure_date);
        $this->assertDatabaseHas('whats_app_messages', [
            'message_id' => 'out.help',
            'body' => 'Puedes decir mañana, el próximo viernes o 2026-10-02.',
        ]);
    }

    public function test_natural_passenger_answer_is_accepted(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*/123/messages' => Http::response(['messages' => [['id' => 'out.passengers']]])]);
        $conversation = WhatsAppConversation::factory()
            ->for(WhatsAppContact::factory()->state(['phone_number' => '5215512345678']), 'contact')
            ->create(['state' => 'ASK_PASSENGERS']);
        WhatsAppFlightRequest::factory()->for($conversation, 'conversation')->create([
            'origin' => 'Querétaro',
            'destination' => 'Cancún',
            'departure_date' => '2026-10-02',
            'departure_time' => '09:00:00',
        ]);

        $this->webhook(['messages' => [[...$this->incomingMessage(), 'id' => 'in.passengers', 'text' => ['body' => 'somos 5 personas']]]])->assertOk();

        $flight = $conversation->flightRequest()->sole();
        $this->assertSame(5, $flight->refresh()->passengers);
        $this->assertSame('ASK_AIRCRAFT_PREFERENCE', $conversation->refresh()->state);
    }

    public function test_natural_time_answer_is_accepted(): void
    {
        $this->travelTo(Carbon::parse('2026-09-21', config('whatsapp.timezone')));
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*/123/messages' => Http::response(['messages' => [['id' => 'out.time']]])]);
        $conversation = WhatsAppConversation::factory()
            ->for(WhatsAppContact::factory()->state(['phone_number' => '5215512345678']), 'contact')
            ->create(['state' => 'ASK_DEPARTURE_TIME']);
        WhatsAppFlightRequest::factory()->for($conversation, 'conversation')->create([
            'origin' => 'Querétaro',
            'destination' => 'Cancún',
            'departure_date' => '2026-10-02',
        ]);

        $this->webhook(['messages' => [[...$this->incomingMessage(), 'id' => 'in.time', 'text' => ['body' => '2 pm']]]])->assertOk();

        $flight = $conversation->flightRequest()->sole();
        $this->assertSame('14:00:00', $flight->departure_time);
        $this->assertSame('ASK_TRIP_TYPE', $conversation->refresh()->state);
    }

    public function test_multicity_legs_are_captured_one_question_at_a_time(): void
    {
        $this->travelTo(Carbon::parse('2026-09-21', config('whatsapp.timezone')));
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*/123/messages' => Http::sequence()
            ->push(['messages' => [['id' => 'out.leg-destination']]])
            ->push(['messages' => [['id' => 'out.leg-date']]])
            ->push(['messages' => [['id' => 'out.leg-time']]])
            ->push(['messages' => [['id' => 'out.leg-next']]])
            ->push(['messages' => [['id' => 'out.aircraft']]])]);
        $conversation = WhatsAppConversation::factory()
            ->for(WhatsAppContact::factory()->state(['phone_number' => '5215512345678']), 'contact')
            ->create(['state' => 'ASK_LEGS']);
        WhatsAppFlightRequest::factory()->for($conversation, 'conversation')->create([
            'origin' => 'Toluca',
            'destination' => 'Cancún',
            'departure_date' => '2026-10-02',
            'departure_time' => '09:00:00',
            'trip_type' => 'MULTI_CITY',
            'passengers' => 4,
        ]);

        $this->webhook(['messages' => [[...$this->incomingMessage(), 'id' => 'in.leg-yes', 'text' => ['body' => 'sí']]]])->assertOk();
        $this->webhook(['messages' => [[...$this->incomingMessage(), 'id' => 'in.leg-destination', 'text' => ['body' => 'Mérida']]]])->assertOk();
        $this->webhook(['messages' => [[...$this->incomingMessage(), 'id' => 'in.leg-date', 'text' => ['body' => '2026-10-03']]]])->assertOk();
        $this->webhook(['messages' => [[...$this->incomingMessage(), 'id' => 'in.leg-time', 'text' => ['body' => '2 pm']]]])->assertOk();
        $this->webhook(['messages' => [[...$this->incomingMessage(), 'id' => 'in.leg-done', 'text' => ['body' => 'continuemos']]]])->assertOk();

        $flight = $conversation->flightRequest()->sole();
        $this->assertSame([[
            'origin' => 'Cancún',
            'destination' => 'Mérida',
            'departure_date' => '2026-10-03',
            'departure_time' => '14:00:00',
        ]], $flight->refresh()->legs);
        $this->assertSame('ASK_AIRCRAFT_PREFERENCE', $conversation->refresh()->state);
        $this->assertDatabaseHas('whats_app_messages', ['message_id' => 'out.leg-destination', 'body' => 'Claro. ¿Cuál sería el siguiente destino?']);
        $this->assertDatabaseHas('whats_app_messages', ['message_id' => 'out.leg-date', 'body' => 'Perfecto, hacia Mérida. ¿Para qué día sería ese tramo?']);
        $this->assertDatabaseHas('whats_app_messages', ['message_id' => 'out.leg-time', 'body' => '¿A qué hora aproximadamente?']);
    }

    public function test_human_transfer_phrases_stop_the_bot(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*/123/messages' => Http::response(['messages' => [['id' => 'out.human']]])]);
        $conversation = WhatsAppConversation::factory()
            ->for(WhatsAppContact::factory()->state(['phone_number' => '5215512345678']), 'contact')
            ->create(['state' => 'ASK_DESTINATION']);
        WhatsAppFlightRequest::factory()->for($conversation, 'conversation')->create(['origin' => 'Toluca']);

        $this->webhook(['messages' => [[...$this->incomingMessage(), 'id' => 'in.human', 'text' => ['body' => 'quiero hablar con alguien']]]])->assertOk();

        $this->assertSame('TRANSFER_TO_HUMAN', $conversation->refresh()->state);
        $this->assertNull($conversation->flightRequest->destination);
        Http::assertSentCount(1);
    }

    public function test_new_quote_intent_clears_only_current_request_and_asks_origin(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*/123/messages' => Http::response(['messages' => [['id' => 'out.new-quote']]])]);
        $conversation = WhatsAppConversation::factory()
            ->for(WhatsAppContact::factory()->state(['phone_number' => '5215512345678']), 'contact')
            ->create(['state' => 'FINISHED', 'metadata' => ['foo' => 'bar']]);
        WhatsAppFlightRequest::factory()->for($conversation, 'conversation')->create([
            'origin' => 'Toluca',
            'destination' => 'Cancún',
            'departure_date' => '2026-10-02',
            'departure_time' => '09:00:00',
            'passengers' => 4,
            'status' => 'quoted',
            'quote_reference' => 'QUOTE-1',
        ]);

        $this->webhook(['messages' => [[...$this->incomingMessage(), 'id' => 'in.new-quote', 'text' => ['body' => 'quiero cotizar otro vuelo']]]])->assertOk();

        $flight = $conversation->flightRequest()->sole();
        $this->assertSame('ASK_ORIGIN', $conversation->refresh()->state);
        $this->assertNull($conversation->metadata);
        $this->assertNull($flight->refresh()->origin);
        $this->assertNull($flight->destination);
        $this->assertSame('collecting', $flight->status);
        $this->assertDatabaseCount('whats_app_flight_requests', 1);
        $this->assertDatabaseHas('whats_app_messages', ['message_id' => 'out.new-quote', 'body' => 'Claro, iniciemos una nueva cotización. ¿Desde qué ciudad o aeropuerto deseas salir?']);
    }

    public function test_quote_status_reports_real_state_without_resetting_request(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*/123/messages' => Http::response(['messages' => [['id' => 'out.status']]])]);
        $conversation = WhatsAppConversation::factory()
            ->for(WhatsAppContact::factory()->state(['phone_number' => '5215512345678']), 'contact')
            ->create(['state' => 'FINISHED']);
        WhatsAppFlightRequest::factory()->for($conversation, 'conversation')->create([
            'origin' => 'Toluca',
            'destination' => 'Cancún',
            'status' => 'quoted',
            'quote_reference' => 'QUOTE-99',
        ]);

        $this->webhook(['messages' => [[...$this->incomingMessage(), 'id' => 'in.status', 'text' => ['body' => 'cómo va mi cotización']]]])->assertOk();

        $this->assertSame('FINISHED', $conversation->refresh()->state);
        $this->assertSame('Toluca', $conversation->flightRequest->origin);
        $this->assertDatabaseHas('whats_app_messages', ['message_id' => 'out.status', 'body' => 'Tu cotización ya fue registrada con referencia QUOTE-99.']);
    }

    public function test_quote_intent_continues_from_missing_data_instead_of_restart(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*/123/messages' => Http::response(['messages' => [['id' => 'out.continue']]])]);
        $conversation = WhatsAppConversation::factory()
            ->for(WhatsAppContact::factory()->state(['phone_number' => '5215512345678']), 'contact')
            ->create(['state' => 'START']);
        WhatsAppFlightRequest::factory()->for($conversation, 'conversation')->create([
            'origin' => 'Toluca',
            'destination' => 'Cancún',
            'passengers' => 6,
        ]);

        $this->webhook(['messages' => [[...$this->incomingMessage(), 'id' => 'in.continue', 'text' => ['body' => 'quiero cotizar']]]])->assertOk();

        $this->assertSame('ASK_DEPARTURE_DATE', $conversation->refresh()->state);
        $this->assertSame(6, $conversation->flightRequest->passengers);
        $this->assertDatabaseHas('whats_app_messages', ['message_id' => 'out.continue', 'body' => 'Perfecto, Toluca → Cancún. ¿Para qué día tienes pensado viajar?']);
    }

    public function test_natural_corrections_update_data_without_restart(): void
    {
        $this->travelTo(Carbon::parse('2026-09-21', config('whatsapp.timezone')));
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*/123/messages' => Http::sequence()
            ->push(['messages' => [['id' => 'out.passenger-correction']]])
            ->push(['messages' => [['id' => 'out.destination-correction']]])
            ->push(['messages' => [['id' => 'out.pet-correction']]])
            ->push(['messages' => [['id' => 'out.date-correction']]])]);
        $conversation = WhatsAppConversation::factory()
            ->for(WhatsAppContact::factory()->state(['phone_number' => '5215512345678']), 'contact')
            ->create(['state' => 'ASK_TRIP_TYPE']);
        WhatsAppFlightRequest::factory()->for($conversation, 'conversation')->create([
            'origin' => 'Toluca',
            'destination' => 'Mérida',
            'departure_date' => '2026-10-02',
            'departure_time' => '09:00:00',
            'passengers' => 4,
            'has_pets' => true,
            'pets_description' => 'perro pequeño',
        ]);

        $this->webhook(['messages' => [[...$this->incomingMessage(), 'id' => 'in.passenger-correction', 'text' => ['body' => 'somos 6']]]])->assertOk();
        $this->webhook(['messages' => [[...$this->incomingMessage(), 'id' => 'in.destination-correction', 'text' => ['body' => 'mejor Cancún']]]])->assertOk();
        $this->webhook(['messages' => [[...$this->incomingMessage(), 'id' => 'in.pet-correction', 'text' => ['body' => 'sin mascotas']]]])->assertOk();
        $this->webhook(['messages' => [[...$this->incomingMessage(), 'id' => 'in.date-correction', 'text' => ['body' => 'cámbialo para mañana']]]])->assertOk();

        $flight = $conversation->flightRequest()->sole()->refresh();
        $this->assertSame(6, $flight->passengers);
        $this->assertSame('Cancún', $flight->destination);
        $this->assertTrue($flight->has_pets);
        $this->assertSame('perro pequeño', $flight->pets_description);
        $this->assertSame('2026-09-22', $flight->departure_date->toDateString());
        $this->assertNotSame('ASK_ORIGIN', $conversation->refresh()->state);
    }

    public function test_location_answers_are_cleaned_without_static_city_mapping(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*/123/messages' => Http::sequence()
            ->push(['messages' => [['id' => 'out.location-origin']]])
            ->push(['messages' => [['id' => 'out.location-destination']]])]);
        $conversation = WhatsAppConversation::factory()
            ->for(WhatsAppContact::factory()->state(['phone_number' => '5215512345678']), 'contact')
            ->create(['state' => 'ASK_ORIGIN']);

        $this->webhook(['messages' => [[...$this->incomingMessage(), 'id' => 'in.location-origin', 'text' => ['body' => 'Aeropuerto de Toluca']]]])->assertOk();
        $this->webhook(['messages' => [[...$this->incomingMessage(), 'id' => 'in.location-destination', 'text' => ['body' => 'tlc']]]])->assertOk();

        $flight = $conversation->flightRequest()->sole();
        $this->assertSame('Toluca', $flight->origin);
        $this->assertSame('TLC', $flight->refresh()->destination);
    }

    public function test_invalid_budget_does_not_throw_or_update_request(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*/123/messages' => Http::response(['messages' => [['id' => 'out.invalid-budget']]])]);
        $conversation = WhatsAppConversation::factory()
            ->for(WhatsAppContact::factory()->state(['phone_number' => '5215512345678']), 'contact')
            ->create(['state' => 'ASK_BUDGET']);
        WhatsAppFlightRequest::factory()->for($conversation, 'conversation')->create(['budget' => null]);

        $this->webhook(['messages' => [[...$this->incomingMessage(), 'id' => 'in.invalid-budget', 'text' => ['body' => 'Demo']]]])->assertOk();

        $flight = $conversation->flightRequest()->sole();
        $this->assertSame('ASK_BUDGET', $conversation->refresh()->state);
        $this->assertNull($flight->refresh()->budget);
        $this->assertNotNull(WhatsAppMessage::query()->where('message_id', 'in.invalid-budget')->sole()->processed_at);
        $this->assertDatabaseHas('whats_app_messages', [
            'message_id' => 'out.invalid-budget',
            'body' => "No alcancé a identificar un presupuesto.\n¿Me puedes dar un monto aproximado? Por ejemplo: 20,000 USD.\nSi todavía no tienes uno, puedes decirme sin presupuesto definido.\n¿Tienes un presupuesto aproximado?",
        ]);
    }

    public function test_valid_budget_is_normalized_before_update(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*/123/messages' => Http::response(['messages' => [['id' => 'out.valid-budget']]])]);
        $conversation = WhatsAppConversation::factory()
            ->for(WhatsAppContact::factory()->state(['phone_number' => '5215512345678']), 'contact')
            ->create(['state' => 'ASK_BUDGET']);
        WhatsAppFlightRequest::factory()->for($conversation, 'conversation')->create(['budget' => null]);

        $this->webhook(['messages' => [[...$this->incomingMessage(), 'id' => 'in.valid-budget', 'text' => ['body' => '20,000 USD']]]])->assertOk();

        $flight = $conversation->flightRequest()->sole();
        $this->assertSame('ASK_NOTES', $conversation->refresh()->state);
        $this->assertEquals(20000, $flight->refresh()->budget);
    }

    public function test_budget_can_be_skipped_without_storing_text(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*/123/messages' => Http::response(['messages' => [['id' => 'out.no-budget']]])]);
        $conversation = WhatsAppConversation::factory()
            ->for(WhatsAppContact::factory()->state(['phone_number' => '5215512345678']), 'contact')
            ->create(['state' => 'ASK_BUDGET']);
        WhatsAppFlightRequest::factory()->for($conversation, 'conversation')->create(['budget' => null]);

        $this->webhook(['messages' => [[...$this->incomingMessage(), 'id' => 'in.no-budget', 'text' => ['body' => 'sin presupuesto definido']]]])->assertOk();

        $flight = $conversation->flightRequest()->sole();
        $this->assertSame('ASK_NOTES', $conversation->refresh()->state);
        $this->assertNull($flight->refresh()->budget);
    }

    public function test_invalid_passenger_count_keeps_state_without_update(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*/123/messages' => Http::response(['messages' => [['id' => 'out.invalid-passengers']]])]);
        $conversation = WhatsAppConversation::factory()
            ->for(WhatsAppContact::factory()->state(['phone_number' => '5215512345678']), 'contact')
            ->create(['state' => 'ASK_PASSENGERS']);
        WhatsAppFlightRequest::factory()->for($conversation, 'conversation')->create(['passengers' => null]);

        $this->webhook(['messages' => [[...$this->incomingMessage(), 'id' => 'in.invalid-passengers', 'text' => ['body' => 'varios']]]])->assertOk();

        $flight = $conversation->flightRequest()->sole();
        $this->assertSame('ASK_PASSENGERS', $conversation->refresh()->state);
        $this->assertNull($flight->refresh()->passengers);
        $this->assertDatabaseHas('whats_app_messages', [
            'message_id' => 'out.invalid-passengers',
            'body' => "Necesito cuántas personas viajan.\n¿Cuántas personas viajan?",
        ]);
    }

    public function test_natural_boolean_answer_is_normalized_before_update(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*/123/messages' => Http::response(['messages' => [['id' => 'out.time-flexibility']]])]);
        $conversation = WhatsAppConversation::factory()
            ->for(WhatsAppContact::factory()->state(['phone_number' => '5215512345678']), 'contact')
            ->create(['state' => 'ASK_TIME_FLEXIBILITY']);
        WhatsAppFlightRequest::factory()->for($conversation, 'conversation')->create(['is_time_flexible' => null]);

        $this->webhook(['messages' => [[...$this->incomingMessage(), 'id' => 'in.time-flexibility', 'text' => ['body' => 'por el momento no']]]])->assertOk();

        $flight = $conversation->flightRequest()->sole();
        $this->assertSame('ASK_ALTERNATE_AIRPORTS', $conversation->refresh()->state);
        $this->assertFalse($flight->refresh()->is_time_flexible);
    }

    public function test_invalid_email_keeps_state_without_update(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*/123/messages' => Http::response(['messages' => [['id' => 'out.invalid-email']]])]);
        $conversation = WhatsAppConversation::factory()
            ->for(WhatsAppContact::factory()->state(['phone_number' => '5215512345678']), 'contact')
            ->create(['state' => 'ASK_EMAIL']);
        WhatsAppFlightRequest::factory()->for($conversation, 'conversation')->create(['client_email' => null]);

        $this->webhook(['messages' => [[...$this->incomingMessage(), 'id' => 'in.invalid-email', 'text' => ['body' => 'correo malo']]]])->assertOk();

        $flight = $conversation->flightRequest()->sole();
        $this->assertSame('ASK_EMAIL', $conversation->refresh()->state);
        $this->assertNull($flight->refresh()->client_email);
        $this->assertDatabaseHas('whats_app_messages', [
            'message_id' => 'out.invalid-email',
            'body' => "Ese correo no parece válido.\n¿Cuál es tu correo electrónico?",
        ]);
    }

    /** @param array<string, mixed>|null $value */
    private function webhook(?array $value = null): TestResponse
    {
        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [
                ['changes' => [['value' => $value ?? ['messages' => [$this->incomingMessage()]]]]],
            ],
        ];

        return $this->postJson('/api/webhooks/whatsapp', $payload, [
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', json_encode($payload), 'test-secret'),
        ]);
    }

    /** @return array{id: string, from: string, type: string, text: array{body: string}} */
    private function incomingMessage(): array
    {
        return ['id' => 'in.once', 'from' => '5215512345678', 'type' => 'text', 'text' => ['body' => 'Hola']];
    }
}
