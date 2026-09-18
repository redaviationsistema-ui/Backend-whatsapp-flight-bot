<?php

namespace Tests\Feature;

use App\Jobs\ProcessWhatsAppMessage;
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
use Tests\TestCase;

class WhatsAppWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_validates_meta_webhook_challenge(): void
    {
        config(['services.whatsapp.verify_token' => 'secret-token']);

        $response = $this->get('/api/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=secret-token&hub.challenge=abc123');

        $response->assertStatus(200);
        $response->assertContent('abc123');
    }

    public function test_it_dispatches_incoming_whatsapp_messages(): void
    {
        Queue::fake();

        $this->postJson('/api/webhooks/whatsapp', $this->payload('wamid.1', 'Hola'))
            ->assertOk()
            ->assertJson(['received' => true]);

        Queue::assertPushed(
            ProcessWhatsAppMessage::class,
            fn (ProcessWhatsAppMessage $job): bool => isset($job->payload['entry'][0]['changes'][0]['value']['messages'][0]),
        );
    }

    public function test_it_processes_conversation_flow_and_ignores_duplicate_message_ids(): void
    {
        config([
            'flight_api.base_url' => 'https://backend.test',
            'flight_api.token' => 'plain-api-token',
            'flight_api.retry_times' => 0,
            'whatsapp.phone_number_id' => null,
            'whatsapp.access_token' => null,
            'services.whatsapp.phone_number_id' => null,
            'services.whatsapp.access_token' => null,
        ]);

        Http::fake([
            'https://backend.test/api/v1/client/quotes/preview' => Http::sequence()
                ->push($this->previewResponse())
                ->push($this->previewResponse()),
            'https://backend.test/api/v1/client/flight-requests' => Http::response([
                'success' => true,
                'flight_request' => ['id' => 3001],
                'accepted_quote' => [
                    'id' => 4001,
                    'total' => 55000,
                    'currency' => 'USD',
                ],
            ]),
        ]);

        $this->process('wamid.1', 'Hola');
        $this->process('wamid.2', 'Toluca');
        $this->process('wamid.3', 'Cancun');
        $this->process('wamid.4', '2026-10-15');
        $this->process('wamid.5', '14:30');
        $this->process('wamid.6', '5');
        $this->process('wamid.7', 'ONE_WAY');
        $this->process('wamid.8', '1');
        $this->process('wamid.8', '1');

        $conversation = WhatsAppConversation::query()->firstOrFail();
        $flightRequest = WhatsAppFlightRequest::query()->firstOrFail();

        $this->assertSame('FINISHED', $conversation->state);
        $this->assertSame('Toluca', $flightRequest->origin);
        $this->assertSame('Cancun', $flightRequest->destination);
        $this->assertSame(5, $flightRequest->passengers);
        $this->assertSame('ONE_WAY', $flightRequest->trip_type);
        $this->assertSame('Gulfstream G-IV', $flightRequest->selected_aircraft);
        $this->assertSame(101, $flightRequest->selected_aircraft_id);
        $this->assertSame(201, $flightRequest->selected_provider_id);
        $this->assertSame('match-101', $flightRequest->selected_match_id);
        $this->assertSame(3001, $flightRequest->backend_flight_request_id);
        $this->assertSame(4001, $flightRequest->accepted_quote_id);
        $this->assertSame('QUOTE-4001', $flightRequest->quote_reference);
        $this->assertSame('quoted', $flightRequest->status);
        $this->assertCount(1, WhatsAppMessage::query()->where('message_id', 'wamid.8')->get());

        Http::assertSent(fn ($request): bool => $request->url() === 'https://backend.test/api/v1/client/quotes/preview'
            && $request->hasHeader('Authorization', 'Bearer plain-api-token')
            && $request['origin'] === 'Toluca'
            && $request['destination'] === 'Cancun'
            && $request['departure_datetime'] === '2026-10-15T14:30:00'
            && $request['trip_type'] === 'one_way'
            && $request['passengers'] === 5
            && is_array($request['legs']));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://backend.test/api/v1/client/flight-requests'
            && $request['aircraft_id'] === 101
            && $request['provider_id'] === 201
            && $request['match_id'] === 'match-101'
            && $request['idempotency_key'] === 'whatsapp-'.$flightRequest->id);
    }

    public function test_first_hola_message_creates_conversation_and_welcome_response(): void
    {
        config([
            'whatsapp.phone_number_id' => null,
            'whatsapp.access_token' => null,
            'services.whatsapp.phone_number_id' => null,
            'services.whatsapp.access_token' => null,
        ]);

        $this->process('wamid.hola', 'hola');

        $conversation = WhatsAppConversation::query()->firstOrFail();

        $this->assertSame('ASK_ORIGIN', $conversation->state);
        $this->assertDatabaseHas('whats_app_messages', [
            'message_id' => 'wamid.hola',
            'direction' => 'inbound',
            'body' => 'hola',
        ]);
        $this->assertDatabaseHas('whats_app_messages', [
            'message_id' => null,
            'direction' => 'outbound',
            'body' => "¡Hola! Bienvenido a Sky Group Aviation ✈️\n¿Desde qué ciudad o aeropuerto deseas salir?",
        ]);
    }

    private function process(string $messageId, string $text): void
    {
        $payload = $this->payload($messageId, $text);
        $messagePayload = [
            'message' => $payload['entry'][0]['changes'][0]['value']['messages'][0],
            'contact' => $payload['entry'][0]['changes'][0]['value']['contacts'][0],
            'metadata' => $payload['entry'][0]['changes'][0]['value']['metadata'],
        ];

        $job = new ProcessWhatsAppMessage($messagePayload);
        $job->handle(
            app(WhatsAppConversationService::class),
            app(WhatsAppMessageService::class),
            app(WhatsAppChatbotService::class),
            app(WhatsAppService::class),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $messageId, string $text): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => '123'],
                        'contacts' => [[
                            'profile' => ['name' => 'Cliente Prueba'],
                            'wa_id' => '5215512345678',
                        ]],
                        'messages' => [[
                            'from' => '5215512345678',
                            'id' => $messageId,
                            'timestamp' => '1790000000',
                            'type' => 'text',
                            'text' => ['body' => $text],
                        ]],
                    ],
                    'field' => 'messages',
                ]],
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function previewResponse(): array
    {
        return [
            'success' => true,
            'options' => [[
                'aircraft_id' => 101,
                'provider_id' => 201,
                'match_id' => 'match-101',
                'aircraft_name' => 'Gulfstream G-IV',
                'capacity' => 12,
                'display_time' => '3:51 hrs',
                'total_amount' => 55000,
                'currency' => 'USD',
                'pricing' => [
                    'total_amount' => 55000,
                    'currency' => 'USD',
                ],
            ]],
        ];
    }
}
