<?php

namespace Tests\Feature;

use App\Models\WhatsAppContact;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppFlightRequest;
use App\Models\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WhatsAppConcurrencyTest extends TestCase
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

    public function test_out_of_order_answers_do_not_overwrite_confirmed_context(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*/123/messages' => Http::sequence()
            ->push(['messages' => [['id' => 'out.destination-first']]])
            ->push(['messages' => [['id' => 'out.origin-late']]])]);
        $conversation = WhatsAppConversation::factory()
            ->for(WhatsAppContact::factory()->state(['phone_number' => '5215512345678']), 'contact')
            ->create(['state' => 'ASK_DESTINATION']);
        $flight = WhatsAppFlightRequest::factory()->for($conversation, 'conversation')->create(['origin' => 'Toluca']);

        $this->webhook('in.destination', 'Cancún')->assertOk();
        $this->webhook('in.origin-late', 'Toluca')->assertOk();

        $flight->refresh();
        $this->assertSame('Toluca', $flight->origin);
        $this->assertSame('Cancún', $flight->destination);
        $this->assertNull($flight->departure_date);
        $this->assertSame('ASK_DEPARTURE_DATE', $conversation->refresh()->state);
        Http::assertSentCount(2);
    }

    public function test_same_message_id_applies_one_transition_even_when_retried_after_send_failure(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*/123/messages' => Http::sequence()
            ->push(['error' => ['message' => 'Meta failed']], 500)
            ->push(['messages' => [['id' => 'out.origin']]])]);
        WhatsAppConversation::factory()
            ->for(WhatsAppContact::factory()->state(['phone_number' => '5215512345678']), 'contact')
            ->create(['state' => 'ASK_ORIGIN']);

        $this->webhook('in.same', 'TLC')->assertInternalServerError();
        $this->webhook('in.same', 'TLC')->assertOk();
        $this->webhook('in.same', 'TLC')->assertOk();

        $flight = WhatsAppFlightRequest::query()->sole();
        $this->assertSame('TLC', $flight->origin);
        $this->assertNull($flight->destination);
        $this->assertSame('ASK_DESTINATION', $flight->conversation->refresh()->state);
        $this->assertDatabaseCount('whats_app_flight_requests', 1);
        $this->assertSame(1, WhatsAppMessage::query()->where('message_id', 'in.same')->count());
        Http::assertSentCount(2);
    }

    public function test_two_contacts_progress_independently_without_mixing_requests(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*/123/messages' => Http::sequence()
            ->push(['messages' => [['id' => 'out.a.origin']]])
            ->push(['messages' => [['id' => 'out.b.origin']]])
            ->push(['messages' => [['id' => 'out.a.destination']]])
            ->push(['messages' => [['id' => 'out.b.destination']]])]);
        WhatsAppConversation::factory()
            ->for(WhatsAppContact::factory()->state(['phone_number' => '5215511111111']), 'contact')
            ->create(['state' => 'ASK_ORIGIN']);
        WhatsAppConversation::factory()
            ->for(WhatsAppContact::factory()->state(['phone_number' => '5215522222222']), 'contact')
            ->create(['state' => 'ASK_ORIGIN']);

        $this->webhook('in.a.origin', 'Toluca', '5215511111111')->assertOk();
        $this->webhook('in.b.origin', 'Monterrey', '5215522222222')->assertOk();
        $this->webhook('in.a.destination', 'Cancún', '5215511111111')->assertOk();
        $this->webhook('in.b.destination', 'Los Cabos', '5215522222222')->assertOk();

        $requests = WhatsAppFlightRequest::query()->with('conversation.contact')->get();
        $a = $requests->firstOrFail(fn (WhatsAppFlightRequest $request): bool => $request->conversation->contact->phone_number === '5215511111111');
        $b = $requests->firstOrFail(fn (WhatsAppFlightRequest $request): bool => $request->conversation->contact->phone_number === '5215522222222');

        $this->assertSame(['Toluca', 'Cancún'], [$a->origin, $a->destination]);
        $this->assertSame(['Monterrey', 'Los Cabos'], [$b->origin, $b->destination]);
        $this->assertNotSame($a->whats_app_conversation_id, $b->whats_app_conversation_id);
        Http::assertSentCount(4);
    }

    private function webhook(string $messageId, string $body, string $from = '5215512345678'): TestResponse
    {
        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Test']]],
                        'messages' => [[
                            'id' => $messageId,
                            'from' => $from,
                            'type' => 'text',
                            'text' => ['body' => $body],
                        ]],
                    ],
                ]],
            ]],
        ];

        return $this->postJson('/api/webhooks/whatsapp', $payload, [
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', json_encode($payload), 'test-secret'),
        ]);
    }
}
