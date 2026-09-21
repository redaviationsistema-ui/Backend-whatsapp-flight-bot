<?php

namespace Tests\Feature;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppFlightRequest;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\WhatsAppChatbotService;
use App\Services\WhatsApp\WhatsAppConversationService;
use App\Services\WhatsApp\WhatsAppMessageService;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class WhatsAppDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_send_keeps_inbound_and_retry_sends_pending_reply_once(): void
    {
        config(['services.whatsapp.phone_number_id' => '123', 'services.whatsapp.access_token' => 'test-token']);
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*/123/messages' => Http::sequence()->push(['error' => []], 500)->push(['messages' => [['id' => 'out.retry']]])]);

        try {
            $this->process('in.retry', 'Hola');
            $this->fail('Expected Meta send failure.');
        } catch (RuntimeException) {
            $this->assertDatabaseHas('whats_app_messages', ['message_id' => 'in.retry', 'processed_at' => null]);
            $this->assertDatabaseCount('whats_app_messages', 1);
            $this->assertDatabaseHas('whats_app_conversations', ['state' => 'ASK_ORIGIN']);
        }
        $this->process('in.retry', 'Hola');
        $this->process('in.retry', 'Hola');

        $this->assertDatabaseCount('whats_app_contacts', 1);
        $this->assertDatabaseCount('whats_app_conversations', 1);
        $this->assertDatabaseCount('whats_app_messages', 2);
        $this->assertNotNull(WhatsAppMessage::query()->where('message_id', 'in.retry')->firstOrFail()->processed_at);
        $this->assertNull(WhatsAppFlightRequest::query()->firstOrFail()->origin);
        $this->assertDatabaseHas('whats_app_messages', ['message_id' => 'out.retry', 'direction' => 'outbound', 'status' => 'sent']);
        Http::assertSentCount(2);
    }

    public function test_retry_resumes_an_inbound_persisted_before_processing(): void
    {
        config(['services.whatsapp.phone_number_id' => '123', 'services.whatsapp.access_token' => 'test-token']);
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*/123/messages' => Http::response(['messages' => [['id' => 'out.persisted']]])]);
        $conversation = WhatsAppConversation::factory()->for(WhatsAppContact::factory()->state(['phone_number' => '5215512345678']), 'contact')->create(['state' => 'ASK_ORIGIN']);
        WhatsAppMessage::factory()->for($conversation, 'conversation')->create(['message_id' => 'in.persisted', 'body' => 'Toluca']);

        $this->process('in.persisted', 'Toluca');

        $this->assertDatabaseHas('whats_app_flight_requests', ['whats_app_conversation_id' => $conversation->id, 'origin' => 'Toluca']);
        $this->assertDatabaseCount('whats_app_messages', 2);
        $this->assertSame('ASK_DESTINATION', $conversation->refresh()->state);
        Http::assertSentCount(1);
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_human_mode_keeps_inbound_in_same_conversation_without_bot_reply(bool $isActive): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*' => Http::response([])]);
        $conversation = WhatsAppConversation::factory()->for(WhatsAppContact::factory()->state(['phone_number' => '5215512345678']), 'contact')->create([
            'state' => 'TRANSFER_TO_HUMAN', 'transferred_to_human_at' => now(), 'is_active' => $isActive,
        ]);

        $this->process('in.human', 'Hola asesor');

        $this->assertDatabaseCount('whats_app_conversations', 1);
        $this->assertDatabaseHas('whats_app_messages', ['message_id' => 'in.human', 'whats_app_conversation_id' => $conversation->id, 'direction' => 'inbound']);
        $this->assertTrue($conversation->refresh()->is_active);
        Http::assertNothingSent();
    }

    public function test_return_to_bot_resumes_capture_in_same_conversation(): void
    {
        config(['services.whatsapp.phone_number_id' => '123', 'services.whatsapp.access_token' => 'test-token']);
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*/123/messages' => Http::response(['messages' => [['id' => 'out.resumed']]])]);
        $conversation = WhatsAppConversation::factory()->for(WhatsAppContact::factory()->state(['phone_number' => '5215512345678']), 'contact')->create(['state' => 'ASK_ORIGIN']);
        $service = app(WhatsAppConversationService::class);
        $service->transferToHuman($conversation);
        $this->process('in.human', 'Consulta humana');
        $service->returnToBot($conversation->refresh());

        $this->process('in.resumed', 'Toluca');

        $this->assertSame('ASK_DESTINATION', $conversation->refresh()->state);
        $this->assertDatabaseHas('whats_app_flight_requests', ['origin' => 'Toluca', 'whats_app_conversation_id' => $conversation->id]);
        Http::assertSentCount(1);
    }

    public function test_automatic_handoff_reply_can_be_retried_after_failed_send(): void
    {
        config(['services.whatsapp.phone_number_id' => '123', 'services.whatsapp.access_token' => 'test-token']);
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*/123/messages' => Http::sequence()->push([], 500)->push(['messages' => [['id' => 'out.handoff']]])]);
        try {
            $this->process('in.handoff', 'asesor');
            $this->fail('Expected send failure.');
        } catch (RuntimeException) {
            $this->assertDatabaseHas('whats_app_conversations', ['state' => 'TRANSFER_TO_HUMAN', 'is_active' => true]);
        }

        $this->process('in.handoff', 'asesor');

        $this->assertDatabaseHas('whats_app_messages', ['message_id' => 'out.handoff', 'direction' => 'outbound']);
        $this->assertNotNull(WhatsAppMessage::query()->where('message_id', 'in.handoff')->firstOrFail()->processed_at);
        Http::assertSentCount(2);
    }

    #[TestWith(['sent'])]
    #[TestWith(['delivered'])]
    #[TestWith(['read'])]
    #[TestWith(['failed'])]
    public function test_signed_status_only_webhook_updates_outbound(string $status): void
    {
        config(['services.whatsapp.app_secret' => 'test-secret']);
        $message = WhatsAppMessage::factory()->create(['message_id' => 'out.status', 'direction' => 'outbound', 'status' => 'sent']);
        $payload = $this->statusPayload($status);

        $this->postJson('/api/webhooks/whatsapp', $payload, $this->signature($payload))->assertOk()->assertJson(['received' => true]);

        $this->assertSame($status, $message->refresh()->status);
        if ($status !== 'sent') {
            $this->assertNotNull($message->{$status.'_at'});
        }
        if ($status === 'failed') {
            $this->assertSame('131047', $message->error_code);
            $this->assertSame('Re-engagement message', $message->error_message);
        }
    }

    public function test_duplicate_and_out_of_order_statuses_never_downgrade_read(): void
    {
        config(['services.whatsapp.app_secret' => 'test-secret']);
        $message = WhatsAppMessage::factory()->create(['message_id' => 'out.status', 'direction' => 'outbound', 'status' => 'sent']);
        foreach (['read', 'sent', 'delivered', 'read'] as $status) {
            $payload = $this->statusPayload($status);
            $this->postJson('/api/webhooks/whatsapp', $payload, $this->signature($payload))->assertOk();
        }
        $this->assertSame('read', $message->refresh()->status);
        $this->assertNotNull($message->delivered_at);
        $this->assertDatabaseCount('whats_app_message_statuses', 3);
    }

    public function test_status_arriving_before_outbound_is_reconciled_after_send(): void
    {
        config(['services.whatsapp.app_secret' => 'test-secret']);
        $payload = $this->statusPayload('read');
        $this->postJson('/api/webhooks/whatsapp', $payload, $this->signature($payload))->assertOk();
        $this->assertDatabaseCount('whats_app_messages', 0);

        $message = app(WhatsAppMessageService::class)->storeOutboundMessage(WhatsAppConversation::factory()->create(), 'Hola', ['messages' => [['id' => 'out.status']]]);

        $this->assertSame('read', $message->status);
        $this->assertNotNull($message->read_at);
    }

    #[TestWith(['sha256=invalid'])]
    #[TestWith([''])]
    public function test_invalid_or_missing_meta_signature_is_rejected(string $signature): void
    {
        Queue::fake();
        config(['services.whatsapp.app_secret' => 'test-secret']);
        $this->postJson('/api/webhooks/whatsapp', $this->statusPayload('sent'), ['X-Hub-Signature-256' => $signature])->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_missing_meta_secret_fails_closed(): void
    {
        Queue::fake();
        config(['services.whatsapp.app_secret' => null]);
        $this->postJson('/api/webhooks/whatsapp', $this->statusPayload('sent'))->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_verification_rejects_wrong_token_on_both_aliases(): void
    {
        config(['services.whatsapp.verify_token' => 'correct']);
        foreach (['/api/webhooks/whatsapp', '/api/whatsapp/webhook'] as $path) {
            $this->get($path.'?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=challenge')->assertForbidden();
            $this->get($path.'?hub.mode=subscribe&hub.verify_token=correct&hub.challenge=challenge')->assertOk()->assertContent('challenge');
        }
    }

    public function test_failed_automated_reply_resumes_without_repeating_search(): void
    {
        config([
            'services.whatsapp.phone_number_id' => '123', 'services.whatsapp.access_token' => 'test-token',
            'flight_api.base_url' => 'https://backend.test', 'flight_api.retry_times' => 0,
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://graph.facebook.com/*/123/messages' => Http::sequence()
                ->push(['messages' => [['id' => 'out.search']]])
                ->push([], 500)
                ->push(['messages' => [['id' => 'out.options']]]),
            'https://backend.test/api/v1/client/quotes/preview' => Http::response(['options' => [['aircraft_id' => 101, 'aircraft_name' => 'Jet']]]),
        ]);
        $conversation = WhatsAppConversation::factory()->for(WhatsAppContact::factory()->state(['phone_number' => '5215512345678']), 'contact')->create(['state' => 'SEARCH_FLIGHTS']);
        WhatsAppFlightRequest::factory()->for($conversation, 'conversation')->create([
            'origin' => 'Toluca', 'destination' => 'Cancún', 'departure_date' => '2026-10-02', 'departure_time' => '14:00:00',
            'passengers' => 4, 'trip_type' => 'ONE_WAY', 'status' => 'confirmed', 'confirmed_at' => now(),
        ]);
        try {
            $this->process('in.search', '1');
            $this->fail('Expected send failure.');
        } catch (RuntimeException) {
            $this->assertSame('SELECT_AIRCRAFT', $conversation->refresh()->state);
        }

        $this->process('in.search', '1');

        $this->assertDatabaseHas('whats_app_messages', ['message_id' => 'out.options']);
        $this->assertNull($conversation->flightRequest->selected_aircraft_id);
        Http::assertSentCount(4);
    }

    private function process(string $id, string $body): void
    {
        $job = new ProcessWhatsAppMessage(['message' => ['id' => $id, 'from' => '5215512345678', 'type' => 'text', 'text' => ['body' => $body]], 'contact' => ['profile' => ['name' => 'Juan Pérez']]]);
        $job->handle(app(WhatsAppConversationService::class), app(WhatsAppMessageService::class), app(WhatsAppChatbotService::class), app(WhatsAppService::class));
    }

    /** @return array<string, mixed> */
    private function statusPayload(string $status): array
    {
        return ['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['value' => ['statuses' => [[
            'id' => 'out.status', 'status' => $status, 'timestamp' => '1790000000', 'errors' => [['code' => 131047, 'title' => 'Re-engagement message']],
        ]]]]]]]];
    }

    /** @param array<string, mixed> $payload
     * @return array<string, string>
     */
    private function signature(array $payload): array
    {
        return ['X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', json_encode($payload), 'test-secret')];
    }
}
