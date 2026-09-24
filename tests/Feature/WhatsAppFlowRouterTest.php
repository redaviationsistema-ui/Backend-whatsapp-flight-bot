<?php

namespace Tests\Feature;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppFlightRequest;
use App\Services\WhatsApp\Flows\FlightQuoteFlowHandler;
use App\Services\WhatsApp\Flows\PartsFlowHandler;
use App\Services\WhatsApp\WhatsAppChatbotService;
use App\Services\WhatsApp\WhatsAppConversationService;
use App\Services\WhatsApp\WhatsAppMessageService;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppFlowRouterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.whatsapp.phone_number_id' => '123',
            'services.whatsapp.access_token' => 'test-token',
        ]);
    }

    public function test_start_can_show_main_menu(): void
    {
        $flight = WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()->state(['state' => 'START']), 'conversation')
            ->create();
        $this->mock(FlightQuoteFlowHandler::class, function ($mock): void {
            $mock->shouldNotReceive('handle');
        });

        $result = app(WhatsAppChatbotService::class)->handleIncomingMessage($flight->conversation, $flight, '.');

        $this->assertSame('MAIN_MENU', $result['state']);
        $this->assertStringContainsString('1. Cotización de vuelo', $result['message']);
        $this->assertNull($flight->conversation->refresh()->metadata);
    }

    public function test_start_greeting_shows_main_menu(): void
    {
        $flight = WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()->state(['state' => 'START']), 'conversation')
            ->create();

        $result = app(WhatsAppChatbotService::class)->handleIncomingMessage($flight->conversation, $flight, 'hola');

        $this->assertSame('MAIN_MENU', $result['state']);
        $this->assertStringContainsString('¿En qué podemos ayudarte?', $result['message']);
        $this->assertNull($flight->conversation->refresh()->metadata);
    }

    public function test_main_menu_option_one_enters_flight(): void
    {
        $flight = $this->flightInMenu();

        $result = app(WhatsAppChatbotService::class)->handleIncomingMessage($flight->conversation, $flight, '1');

        $this->assertSame('ASK_ORIGIN', $result['state']);
        $this->assertSame('FLIGHT', $flight->conversation->refresh()->metadata['active_section']);
        $this->assertStringContainsString('¿Desde qué ciudad', $result['message']);
    }

    public function test_main_menu_quote_text_enters_flight(): void
    {
        $flight = $this->flightInMenu();

        $result = app(WhatsAppChatbotService::class)->handleIncomingMessage($flight->conversation, $flight, 'cotización');

        $this->assertSame('ASK_ORIGIN', $result['state']);
        $this->assertSame('FLIGHT', $flight->conversation->refresh()->metadata['active_section']);
    }

    public function test_active_section_flight_delegates_to_flight_quote_flow_handler(): void
    {
        $flight = WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()->state([
                'state' => 'ASK_ORIGIN',
                'metadata' => ['active_section' => 'FLIGHT'],
            ]), 'conversation')
            ->create();
        $this->mock(FlightQuoteFlowHandler::class, function ($mock) use ($flight): void {
            $mock->shouldReceive('handle')
                ->once()
                ->withArgs(fn (WhatsAppConversation $conversation, WhatsAppFlightRequest $flightRequest, string $message): bool => $conversation->is($flight->conversation)
                    && $flightRequest->is($flight)
                    && $message === 'Toluca')
                ->andReturn(['state' => 'ASK_DESTINATION', 'message' => 'handler-ok']);
        });

        $result = app(WhatsAppChatbotService::class)->handleIncomingMessage($flight->conversation, $flight, 'Toluca');

        $this->assertSame(['state' => 'ASK_DESTINATION', 'message' => 'handler-ok'], $result);
    }

    public function test_legacy_flight_state_without_active_section_restores_flight(): void
    {
        $flight = WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()->state(['state' => 'ASK_ORIGIN']), 'conversation')
            ->create();
        $this->mock(FlightQuoteFlowHandler::class, function ($mock) use ($flight): void {
            $mock->shouldReceive('handle')
                ->once()
                ->withArgs(fn (WhatsAppConversation $conversation, WhatsAppFlightRequest $flightRequest, string $message): bool => $conversation->is($flight->conversation)
                    && $flightRequest->is($flight)
                    && $message === 'Toluca')
                ->andReturn(['state' => 'ASK_DESTINATION', 'message' => 'handler-ok']);
        });

        $result = app(WhatsAppChatbotService::class)->handleIncomingMessage($flight->conversation, $flight, 'Toluca');

        $this->assertSame(['state' => 'ASK_DESTINATION', 'message' => 'handler-ok'], $result);
        $this->assertSame('FLIGHT', $flight->conversation->refresh()->metadata['active_section']);
    }

    public function test_main_menu_parts_option_does_not_execute_flight_quote_flow_handler(): void
    {
        $flight = $this->flightInMenu();
        $this->mock(FlightQuoteFlowHandler::class, function ($mock): void {
            $mock->shouldNotReceive('handle');
        });

        $result = app(WhatsAppChatbotService::class)->handleIncomingMessage($flight->conversation, $flight, '2');

        $this->assertSame('PARTS_ASK_PART_NUMBER', $result['state']);
        $this->assertSame('PARTS', $flight->conversation->refresh()->metadata['active_section']);
        $this->assertStringContainsString('Partes y refacciones', $result['message']);
    }

    public function test_select_aircraft_one_is_not_treated_as_menu_option(): void
    {
        $flight = WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()->state([
                'state' => 'SELECT_AIRCRAFT',
                'metadata' => ['active_section' => 'FLIGHT'],
            ]), 'conversation')
            ->create([
                'confirmed_at' => now(),
                'search_results' => [['aircraft_id' => 'not-a-uuid', 'aircraft_name' => 'Jet']],
            ]);

        $result = app(WhatsAppChatbotService::class)->handleIncomingMessage($flight->conversation, $flight, '1');

        $this->assertSame('SEARCH_FLIGHTS', $result['state']);
        $this->assertStringContainsString('identificador válido', $result['message']);
    }

    public function test_menu_from_flight_returns_to_main_menu_and_clears_active_section(): void
    {
        $flight = WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()->state([
                'state' => 'ASK_DESTINATION',
                'metadata' => ['active_section' => 'FLIGHT'],
            ]), 'conversation')
            ->create(['origin' => 'Toluca']);

        $result = app(WhatsAppChatbotService::class)->handleIncomingMessage($flight->conversation, $flight, 'menú');

        $this->assertSame('MAIN_MENU', $result['state']);
        $this->assertNull($flight->conversation->refresh()->metadata);
        $this->assertSame('Toluca', $flight->refresh()->origin);
    }

    public function test_cancel_returns_to_main_menu_without_clearing_request_context(): void
    {
        $flight = WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()->state([
                'state' => 'ASK_DESTINATION',
                'metadata' => ['active_section' => 'FLIGHT'],
            ]), 'conversation')
            ->create(['origin' => 'Toluca']);

        $result = app(WhatsAppChatbotService::class)->handleIncomingMessage($flight->conversation, $flight, 'cancelar');

        $this->assertSame('MAIN_MENU', $result['state']);
        $this->assertStringContainsString('Solicitud cancelada.', $result['message']);
        $this->assertNull($flight->conversation->refresh()->metadata);
        $this->assertSame('cancelled', $flight->refresh()->status);
        $this->assertSame('Toluca', $flight->origin);
    }

    public function test_advisor_preserves_and_return_to_bot_restores_active_section(): void
    {
        $flight = WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()->state([
                'state' => 'SELECT_AIRCRAFT',
                'metadata' => ['active_section' => 'FLIGHT'],
            ]), 'conversation')
            ->create();
        $conversationService = app(WhatsAppConversationService::class);

        $result = app(WhatsAppChatbotService::class)->handleIncomingMessage($flight->conversation, $flight, 'asesor');
        $conversationService->transferToHuman($flight->conversation);

        $this->assertSame('TRANSFER_TO_HUMAN', $result['state']);
        $this->assertSame('SELECT_AIRCRAFT', $flight->conversation->refresh()->metadata['bot_state_before_transfer']);
        $this->assertSame('FLIGHT', $flight->conversation->metadata['active_section_before_transfer']);

        $conversationService->returnToBot($flight->conversation->refresh());

        $this->assertSame('SELECT_AIRCRAFT', $flight->conversation->refresh()->state);
        $this->assertSame('FLIGHT', $flight->conversation->metadata['active_section']);
    }

    public function test_main_menu_engines_option_does_not_execute_other_flow_handlers(): void
    {
        $flight = $this->flightInMenu();
        $this->mock(FlightQuoteFlowHandler::class, function ($mock): void {
            $mock->shouldNotReceive('handle');
        });
        $this->mock(PartsFlowHandler::class, function ($mock): void {
            $mock->shouldNotReceive('handle');
        });

        $result = app(WhatsAppChatbotService::class)->handleIncomingMessage($flight->conversation, $flight, '3');

        $this->assertSame('ENGINE_ASK_MODEL', $result['state']);
        $this->assertSame('ENGINES', $flight->conversation->refresh()->metadata['active_section']);
        $this->assertStringContainsString('Motores', $result['message']);
    }

    public function test_status_payload_without_messages_does_not_run_router(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*' => Http::response([])]);

        $job = new ProcessWhatsAppMessage([
            'entry' => [[
                'changes' => [[
                    'value' => ['statuses' => [['id' => 'out.status', 'status' => 'read', 'timestamp' => '1790000000']]],
                ]],
            ]],
        ]);
        $job->handle(app(WhatsAppConversationService::class), app(WhatsAppMessageService::class), app(WhatsAppChatbotService::class), app(WhatsAppService::class));

        $this->assertDatabaseCount('whats_app_conversations', 0);
        $this->assertDatabaseCount('whats_app_flight_requests', 0);
        Http::assertNothingSent();
    }

    private function flightInMenu(): WhatsAppFlightRequest
    {
        return WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()
                ->for(WhatsAppContact::factory()->state(['phone_number' => '5215512345678']), 'contact')
                ->state(['state' => 'MAIN_MENU']), 'conversation')
            ->create();
    }
}
