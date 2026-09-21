<?php

namespace Tests\Feature;

use App\Models\WhatsAppContact;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppFlightRequest;
use App\Models\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_automated_stages_resume_without_repeating_search_or_either_reply(): void
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

        $this->webhook()->assertInternalServerError();
        $this->webhook()->assertOk();
        $this->webhook()->assertOk();

        $this->assertDatabaseCount('whats_app_messages', 3);
        $this->assertSame('SELECT_AIRCRAFT', $conversation->refresh()->state);
        $this->assertNull($conversation->flightRequest->selected_aircraft_id);
        Http::assertSentCount(3);
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
