<?php

namespace Tests\Feature;

use App\Models\EngineRequest;
use App\Models\PartRequest;
use App\Models\SupportRequest;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppFlightRequest;
use App\Services\WhatsApp\Flows\EngineFlowHandler;
use App\Services\WhatsApp\Flows\FlightQuoteFlowHandler;
use App\Services\WhatsApp\Flows\PartsFlowHandler;
use App\Services\WhatsApp\Flows\SupportFlowHandler;
use App\Services\WhatsApp\WhatsAppChatbotService;
use App\Services\WhatsApp\WhatsAppConversationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsAppInformationFlowTest extends TestCase
{
    use RefreshDatabase;

    private int $phoneSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.whatsapp.phone_number_id' => '123',
            'services.whatsapp.access_token' => 'test-token',
        ]);
    }

    public function test_main_menu_option_five_enters_info_flow(): void
    {
        $flight = $this->flightInMenu();

        $result = $this->answer($flight, '5');

        $this->assertSame('INFO_MENU', $result['state']);
        $this->assertSame('INFO', $flight->conversation->refresh()->metadata['active_section']);
        $this->assertArrayNotHasKey('section_context', $flight->conversation->metadata);
        $this->assertStringContainsString('Información', $result['message']);
    }

    public function test_information_text_from_main_menu_enters_info_flow(): void
    {
        $flight = $this->flightInMenu();

        $result = $this->answer($flight, 'información');

        $this->assertSame('INFO_MENU', $result['state']);
        $this->assertSame('INFO', $flight->conversation->refresh()->metadata['active_section']);
    }

    public function test_info_entry_does_not_execute_other_flow_handlers(): void
    {
        $flight = $this->flightInMenu();
        $this->mock(FlightQuoteFlowHandler::class, function ($mock): void {
            $mock->shouldNotReceive('handle');
        });
        $this->mock(PartsFlowHandler::class, function ($mock): void {
            $mock->shouldNotReceive('handle');
        });
        $this->mock(EngineFlowHandler::class, function ($mock): void {
            $mock->shouldNotReceive('handle');
        });
        $this->mock(SupportFlowHandler::class, function ($mock): void {
            $mock->shouldNotReceive('handle');
        });

        $result = app(WhatsAppChatbotService::class)->handleIncomingMessage($flight->conversation, $flight, '5');

        $this->assertSame('INFO_MENU', $result['state']);
        $this->assertSame('INFO', $flight->conversation->refresh()->metadata['active_section']);
    }

    public function test_info_menu_options_show_expected_topics(): void
    {
        $expectations = [
            '1' => ['INFO_SERVICES', 'Nuestros servicios incluyen'],
            '2' => ['INFO_FLEET', 'Turbohélices'],
            '3' => ['INFO_DESTINATIONS', 'vuelos nacionales e internacionales'],
            '4' => ['INFO_PARTS', 'Número de parte'],
            '5' => ['INFO_ENGINES', 'motores aeronáuticos'],
            '6' => ['INFO_CONTACT', 'este mismo canal de WhatsApp'],
            '7' => ['INFO_FAQ', 'Preguntas frecuentes'],
        ];

        foreach ($expectations as $input => [$state, $message]) {
            $flight = $this->infoFlightAt('INFO_MENU');
            $result = $this->answer($flight, $input);

            $this->assertSame($state, $result['state']);
            $this->assertStringContainsString($message, $result['message']);
            $this->assertStringContainsString('1. Volver a Información', $result['message']);
            $this->assertStringContainsString('2. Menú principal', $result['message']);
        }
    }

    public function test_option_eight_menu_and_cancel_return_to_main_menu(): void
    {
        $flight = $this->infoFlightAt('INFO_MENU');

        $option = $this->answer($flight, '8');

        $this->assertSame('MAIN_MENU', $option['state']);

        $flight = $this->infoFlightAt('INFO_FLEET');
        $menu = $this->answer($flight, 'menu');

        $this->assertSame('MAIN_MENU', $menu['state']);
        $this->assertNull($flight->conversation->refresh()->metadata);

        $flight = $this->infoFlightAt('INFO_FAQ');
        $cancel = $this->answer($flight, 'cancelar');

        $this->assertSame('MAIN_MENU', $cancel['state']);
        $this->assertSame('✅ Volvimos al menú principal.', $cancel['message']);
        $this->assertNull($flight->conversation->refresh()->metadata);
    }

    public function test_topic_navigation_returns_to_info_or_main_menu(): void
    {
        $flight = $this->infoFlightAt('INFO_SERVICES');

        $back = $this->answer($flight, '1');

        $this->assertSame('INFO_MENU', $back['state']);
        $this->assertStringContainsString('¿Qué deseas consultar?', $back['message']);

        $flight = $this->infoFlightAt('INFO_SERVICES');
        $main = $this->answer($flight, '2');

        $this->assertSame('MAIN_MENU', $main['state']);
    }

    public function test_advisor_and_return_to_bot_restore_info_context(): void
    {
        $flight = $this->infoFlightAt('INFO_FAQ');
        $conversationService = app(WhatsAppConversationService::class);

        $conversation = $flight->conversation()->firstOrFail();
        $result = app(WhatsAppChatbotService::class)->handleIncomingMessage($conversation, $flight->refresh(), 'asesor');
        $conversationService->transferToHuman($conversation);

        $this->assertSame('TRANSFER_TO_HUMAN', $result['state']);
        $this->assertSame('INFO', $flight->conversation->refresh()->metadata['active_section_before_transfer']);
        $this->assertSame('INFO_FAQ', $flight->conversation->metadata['bot_state_before_transfer']);

        $conversationService->returnToBot($flight->conversation->refresh());

        $this->assertSame('INFO_FAQ', $flight->conversation->refresh()->state);
        $this->assertSame('INFO', $flight->conversation->metadata['active_section']);
    }

    public function test_info_flow_does_not_create_or_modify_records(): void
    {
        $flight = $this->infoFlightAt('INFO_MENU');
        $partRequest = PartRequest::query()->create([
            'whats_app_conversation_id' => $flight->conversation->id,
            'whats_app_contact_id' => $flight->conversation->whats_app_contact_id,
            'part_number' => 'ABC',
            'quantity' => 1,
            'condition' => 'SERVICEABLE',
            'status' => PartRequest::StatusNueva,
        ]);
        $engineRequest = EngineRequest::query()->create([
            'whats_app_conversation_id' => $flight->conversation->id,
            'whats_app_contact_id' => $flight->conversation->whats_app_contact_id,
            'engine_model' => 'PW305A',
            'condition' => 'SERVICEABLE',
            'service_type' => 'SELL',
            'status' => EngineRequest::StatusNueva,
        ]);
        $supportRequest = SupportRequest::query()->create([
            'whats_app_conversation_id' => $flight->conversation->id,
            'whats_app_contact_id' => $flight->conversation->whats_app_contact_id,
            'reason' => 'PAYMENT',
            'description' => 'Ayuda',
            'priority' => 'NORMAL',
            'status' => SupportRequest::StatusNueva,
        ]);

        $this->answer($flight, '1');
        $this->answer($flight, 'volver');
        $this->answer($flight, '7');

        $this->assertDatabaseCount('whats_app_flight_requests', 1);
        $this->assertDatabaseCount('parts_requests', 1);
        $this->assertDatabaseCount('engine_requests', 1);
        $this->assertDatabaseCount('support_requests', 1);
        $this->assertNull($flight->refresh()->origin);
        $this->assertNull($flight->selected_aircraft_id);
        $this->assertSame('ABC', $partRequest->refresh()->part_number);
        $this->assertSame('PW305A', $engineRequest->refresh()->engine_model);
        $this->assertSame('PAYMENT', $supportRequest->refresh()->reason);
    }

    private function infoFlightAt(string $state): WhatsAppFlightRequest
    {
        return WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()
                ->for(WhatsAppContact::factory()->state(['phone_number' => $this->phoneNumber()]), 'contact')
                ->state([
                    'state' => $state,
                    'metadata' => ['active_section' => 'INFO'],
                ]), 'conversation')
            ->create([
                'origin' => null,
                'destination' => null,
                'selected_aircraft_id' => null,
                'official_quote_payload' => null,
            ]);
    }

    private function flightInMenu(): WhatsAppFlightRequest
    {
        return WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()
                ->for(WhatsAppContact::factory()->state(['phone_number' => $this->phoneNumber()]), 'contact')
                ->state(['state' => 'MAIN_MENU']), 'conversation')
            ->create();
    }

    /** @return array{state:string,message:string} */
    private function answer(WhatsAppFlightRequest $flight, string $answer): array
    {
        $conversation = $flight->conversation()->firstOrFail();
        $result = app(WhatsAppChatbotService::class)->handleIncomingMessage($conversation, $flight->refresh(), $answer);
        $conversation->update(['state' => $result['state']]);

        return $result;
    }

    private function phoneNumber(): string
    {
        $this->phoneSequence++;

        return '521554567'.str_pad((string) $this->phoneSequence, 4, '0', STR_PAD_LEFT);
    }
}
