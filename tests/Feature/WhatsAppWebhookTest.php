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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WhatsAppWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const AIRCRAFT_UUID = '16450000-0000-4000-8000-000000000001';

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
        config(['services.whatsapp.app_secret' => 'test-meta-secret']);

        $this->postJson('/api/webhooks/whatsapp', $this->payload('wamid.1', 'Hola'), ['X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', json_encode($this->payload('wamid.1', 'Hola')), 'test-meta-secret')])
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
            'flight_api.mode' => 'remote',
            'flight_api.base_url' => 'https://backend.test',
            'flight_api.token' => 'plain-api-token',
            'flight_api.retry_times' => 0,
            'whatsapp.phone_number_id' => null,
            'whatsapp.access_token' => null,
            'services.whatsapp.phone_number_id' => '123',
            'services.whatsapp.access_token' => 'test-access-token',
        ]);

        $this->travelTo(Carbon::parse('2026-09-20'));
        Http::preventStrayRequests();
        $sequence = 0;
        Http::fake([
            'https://graph.facebook.com/*/123/messages' => function () use (&$sequence) {
                return Http::response(['messages' => [['id' => 'out.'.++$sequence]]]);
            },
            'https://backend.test/api/v1/client/quotes/preview' => Http::sequence()
                ->push($this->previewResponse())
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

        $answers = ['Hola', '1', 'Toluca', 'Cancun', '2026-10-15', '14:30', '1', '5', 'sin preferencia', 'ninguno', 'Juan Pérez', 'juan@example.com', 'omitir', 'omitir', 'ninguna', '1', 'continuar', 'continuar', '1', 'continuar'];
        foreach ($answers as $index => $answer) {
            $this->process('wamid.'.($index + 1), $answer);
        }
        $this->process('wamid.22', 'continuar');

        $conversation = WhatsAppConversation::query()->firstOrFail();
        $flightRequest = WhatsAppFlightRequest::query()->firstOrFail();

        $this->assertSame('FINISHED', $conversation->state);
        $this->assertSame('Toluca', $flightRequest->origin);
        $this->assertSame('Cancun', $flightRequest->destination);
        $this->assertSame(5, $flightRequest->passengers);
        $this->assertSame('ONE_WAY', $flightRequest->trip_type);
        $this->assertSame('Gulfstream G-IV', $flightRequest->selected_aircraft);
        $this->assertSame(self::AIRCRAFT_UUID, $flightRequest->selected_aircraft_id);
        $this->assertSame(201, $flightRequest->selected_provider_id);
        $this->assertSame('match-101', $flightRequest->selected_match_id);
        $this->assertSame(3001, $flightRequest->backend_flight_request_id);
        $this->assertSame(4001, $flightRequest->accepted_quote_id);
        $this->assertSame('QUOTE-4001', $flightRequest->quote_reference);
        $this->assertSame('quoted', $flightRequest->status);
        $this->assertCount(1, WhatsAppMessage::query()->where('message_id', 'wamid.22')->get());

        Http::assertSent(fn ($request): bool => $request->url() === 'https://backend.test/api/v1/client/quotes/preview'
            && $request->hasHeader('Authorization', 'Bearer plain-api-token')
            && $request['origin'] === 'Toluca'
            && $request['destination'] === 'Cancun'
            && $request['departure_datetime'] === '2026-10-15T14:30:00'
            && $request['trip_type'] === 'one_way'
            && $request['passengers'] === 5
            && is_array($request['legs']));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://backend.test/api/v1/client/flight-requests'
            && $request['aircraft_id'] === self::AIRCRAFT_UUID
            && $request['provider_id'] === 201
            && $request['match_id'] === 'match-101'
            && $request['idempotency_key'] === 'whatsapp-'.$flightRequest->id);
    }

    public function test_first_hola_message_creates_conversation_and_welcome_response(): void
    {
        config([
            'whatsapp.phone_number_id' => null,
            'whatsapp.access_token' => null,
            'services.whatsapp.phone_number_id' => '123',
            'services.whatsapp.access_token' => 'test-access-token',
        ]);

        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*/123/messages' => Http::response(['messages' => [['id' => 'out.hola']]])]);
        $this->process('wamid.hola', 'hola');

        $conversation = WhatsAppConversation::query()->firstOrFail();

        $this->assertSame('MAIN_MENU', $conversation->state);
        $this->assertDatabaseHas('whats_app_messages', [
            'message_id' => 'wamid.hola',
            'direction' => 'inbound',
            'body' => 'hola',
        ]);
        $this->assertDatabaseHas('whats_app_messages', [
            'message_id' => 'out.hola',
            'direction' => 'outbound',
            'body' => "👋 ¡Hola! Bienvenido a *Sky Group Aviation* ✈️\n\n¿En qué podemos ayudarte?\n\n✈️ 1️⃣ Cotización de vuelo\n🔧 2️⃣ Partes y refacciones\n⚙️ 3️⃣ Motores\n🎧 4️⃣ Atención / soporte\nℹ️ 5️⃣ Información\n👨‍💼 6️⃣ Hablar con un asesor\n\n👉 Responde con el número de la opción.",
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
                'aircraft_id' => self::AIRCRAFT_UUID,
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
