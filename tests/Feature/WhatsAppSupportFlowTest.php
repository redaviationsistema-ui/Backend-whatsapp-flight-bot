<?php

namespace Tests\Feature;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\EngineRequest;
use App\Models\PartRequest;
use App\Models\SupportRequest;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppFlightRequest;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\Flows\EngineFlowHandler;
use App\Services\WhatsApp\Flows\FlightQuoteFlowHandler;
use App\Services\WhatsApp\Flows\PartsFlowHandler;
use App\Services\WhatsApp\WhatsAppChatbotService;
use App\Services\WhatsApp\WhatsAppConversationService;
use App\Services\WhatsApp\WhatsAppMessageService;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppSupportFlowTest extends TestCase
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

    public function test_main_menu_option_four_enters_support_flow(): void
    {
        $flight = $this->flightInMenu();

        $result = $this->answer($flight, '4');

        $this->assertSame('SUPPORT_ASK_REASON', $result['state']);
        $this->assertSame('SUPPORT', $flight->conversation->refresh()->metadata['active_section']);
        $this->assertStringContainsString('Atención / soporte', $result['message']);
    }

    public function test_support_text_from_main_menu_enters_support_flow(): void
    {
        $flight = $this->flightInMenu();

        $result = $this->answer($flight, 'soporte');

        $this->assertSame('SUPPORT_ASK_REASON', $result['state']);
        $this->assertSame('SUPPORT', $flight->conversation->refresh()->metadata['active_section']);
    }

    public function test_support_entry_does_not_execute_other_flow_handlers(): void
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

        $result = app(WhatsAppChatbotService::class)->handleIncomingMessage($flight->conversation, $flight, '4');

        $this->assertSame('SUPPORT_ASK_REASON', $result['state']);
        $this->assertSame('SUPPORT', $flight->conversation->refresh()->metadata['active_section']);
    }

    public function test_support_flow_captures_summary_and_does_not_touch_other_modules(): void
    {
        $flight = $this->flightInMenu();
        $this->answer($flight, '4');
        $this->syncState($flight, 'SUPPORT_ASK_REASON');
        $this->answer($flight, '3');
        $this->answer($flight, 'COT-123');
        $this->answer($flight, 'No puedo completar el pago.');
        $this->answer($flight, '1');
        $result = $this->answer($flight, 'no');

        $context = $flight->conversation->refresh()->metadata['section_context']['SUPPORT'];
        $this->assertSame('PAYMENT', $context['reason']);
        $this->assertSame('COT-123', $context['reference']);
        $this->assertSame('No puedo completar el pago.', $context['description']);
        $this->assertSame('NORMAL', $context['priority']);
        $this->assertNull($context['comments']);
        $this->assertSame('SUPPORT_SHOW_SUMMARY', $result['state']);
        $this->assertStringContainsString('Motivo: Pago', $result['message']);
        $this->assertStringContainsString('Referencia: COT-123', $result['message']);
        $this->assertStringContainsString('Prioridad: Normal', $result['message']);
        $this->assertDatabaseCount('parts_requests', 0);
        $this->assertDatabaseCount('engine_requests', 0);
        $this->assertNull($flight->refresh()->origin);
        $this->assertNull($flight->selected_aircraft_id);
    }

    public function test_reason_options_are_normalized(): void
    {
        $flight = $this->supportFlightAt('SUPPORT_ASK_REASON', []);

        $this->answer($flight, '1');

        $this->assertSame('FLIGHT_QUOTE', $flight->conversation->refresh()->metadata['section_context']['SUPPORT']['reason']);

        $flight = $this->supportFlightAt('SUPPORT_ASK_REASON', []);
        $this->answer($flight, '3');

        $this->assertSame('PAYMENT', $flight->conversation->refresh()->metadata['section_context']['SUPPORT']['reason']);
    }

    public function test_reference_accepts_no_and_description_is_required(): void
    {
        $flight = $this->supportFlightAt('SUPPORT_ASK_REFERENCE', ['reason' => 'PAYMENT']);

        $reference = $this->answer($flight, 'no');
        $invalidDescription = $this->answer($flight, '   ');

        $this->assertSame('SUPPORT_ASK_DESCRIPTION', $reference['state']);
        $this->assertNull($flight->conversation->refresh()->metadata['section_context']['SUPPORT']['reference']);
        $this->assertSame('SUPPORT_ASK_DESCRIPTION', $invalidDescription['state']);
        $this->assertArrayNotHasKey('description', $flight->conversation->refresh()->metadata['section_context']['SUPPORT']);
    }

    public function test_priority_options_are_normalized(): void
    {
        $flight = $this->supportFlightAt('SUPPORT_ASK_PRIORITY', ['reason' => 'PAYMENT', 'description' => 'Ayuda']);

        $this->answer($flight, '1');

        $this->assertSame('NORMAL', $flight->conversation->refresh()->metadata['section_context']['SUPPORT']['priority']);

        $flight = $this->supportFlightAt('SUPPORT_ASK_PRIORITY', ['reason' => 'PAYMENT', 'description' => 'Ayuda']);
        $this->answer($flight, '2');

        $this->assertSame('HIGH', $flight->conversation->refresh()->metadata['section_context']['SUPPORT']['priority']);

        $flight = $this->supportFlightAt('SUPPORT_ASK_PRIORITY', ['reason' => 'PAYMENT', 'description' => 'Ayuda']);
        $this->answer($flight, 'urgente');

        $this->assertSame('URGENT', $flight->conversation->refresh()->metadata['section_context']['SUPPORT']['priority']);
    }

    public function test_edit_updates_only_selected_field(): void
    {
        $flight = $this->completeSupportSummary();

        $this->answer($flight, '2');
        $this->answer($flight, '4');
        $result = $this->answer($flight, '2');

        $context = $flight->conversation->refresh()->metadata['section_context']['SUPPORT'];
        $this->assertSame('SUPPORT_SHOW_SUMMARY', $result['state']);
        $this->assertSame('PAYMENT', $context['reason']);
        $this->assertSame('COT-123', $context['reference']);
        $this->assertSame('No puedo completar el pago.', $context['description']);
        $this->assertSame('HIGH', $context['priority']);
        $this->assertNull($context['comments']);
    }

    public function test_confirm_creates_support_request_once(): void
    {
        $flight = $this->completeSupportSummary();

        $result = $this->answer($flight, '1');

        $this->assertSame('SUPPORT_COMPLETED', $result['state']);
        $supportRequest = SupportRequest::query()->sole();
        $this->assertSame($flight->conversation->id, $supportRequest->whats_app_conversation_id);
        $this->assertSame($flight->conversation->whats_app_contact_id, $supportRequest->whats_app_contact_id);
        $this->assertSame('PAYMENT', $supportRequest->reason);
        $this->assertSame('COT-123', $supportRequest->reference);
        $this->assertSame('NORMAL', $supportRequest->priority);
        $this->assertSame('NUEVA', $supportRequest->status);
        $this->assertSame('SUPPORT', $flight->conversation->refresh()->metadata['active_section']);
    }

    public function test_duplicate_confirm_message_does_not_duplicate_support_request(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*/123/messages' => Http::response(['messages' => [['id' => 'out.support']]])]);
        $flight = $this->completeSupportSummary();

        $this->process('in.support-confirm', '1', $flight->conversation->contact->phone_number);
        $this->process('in.support-confirm', '1', $flight->conversation->contact->phone_number);

        $this->assertDatabaseCount('support_requests', 1);
        $this->assertSame('SUPPORT_COMPLETED', $flight->conversation->refresh()->state);
        $this->assertNotNull(WhatsAppMessage::query()->where('message_id', 'in.support-confirm')->sole()->processed_at);
        Http::assertSentCount(1);
    }

    public function test_menu_clears_support_context_and_cancel_does_not_create_ticket(): void
    {
        $flight = $this->supportFlightAt('SUPPORT_ASK_REFERENCE', ['reason' => 'PAYMENT']);

        $menu = $this->answer($flight, 'menu');

        $this->assertSame('MAIN_MENU', $menu['state']);
        $this->assertNull($flight->conversation->refresh()->metadata);

        $flight = $this->supportFlightAt('SUPPORT_ASK_REFERENCE', ['reason' => 'PAYMENT']);
        $cancel = $this->answer($flight, 'cancelar');

        $this->assertSame('MAIN_MENU', $cancel['state']);
        $this->assertStringContainsString('Solicitud de soporte cancelada.', $cancel['message']);
        $this->assertDatabaseCount('support_requests', 0);
    }

    public function test_advisor_and_return_to_bot_restore_support_context(): void
    {
        $flight = $this->supportFlightAt('SUPPORT_ASK_PRIORITY', ['reason' => 'PAYMENT', 'description' => 'Ayuda']);
        $conversationService = app(WhatsAppConversationService::class);

        $conversation = $flight->conversation()->firstOrFail();
        $result = app(WhatsAppChatbotService::class)->handleIncomingMessage($conversation, $flight->refresh(), 'asesor');
        $conversationService->transferToHuman($conversation);

        $this->assertSame('TRANSFER_TO_HUMAN', $result['state']);
        $this->assertSame('SUPPORT', $flight->conversation->refresh()->metadata['active_section_before_transfer']);
        $this->assertSame('SUPPORT_ASK_PRIORITY', $flight->conversation->metadata['bot_state_before_transfer']);

        $conversationService->returnToBot($flight->conversation->refresh());

        $this->assertSame('SUPPORT_ASK_PRIORITY', $flight->conversation->refresh()->state);
        $this->assertSame('SUPPORT', $flight->conversation->metadata['active_section']);
        $this->assertSame('PAYMENT', $flight->conversation->metadata['section_context']['SUPPORT']['reason']);
    }

    public function test_support_flow_does_not_modify_existing_part_or_engine_requests(): void
    {
        $flight = $this->completeSupportSummary();
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

        $this->answer($flight, '1');

        $this->assertDatabaseCount('support_requests', 1);
        $this->assertDatabaseCount('parts_requests', 1);
        $this->assertDatabaseCount('engine_requests', 1);
        $this->assertSame('ABC', $partRequest->refresh()->part_number);
        $this->assertSame('PW305A', $engineRequest->refresh()->engine_model);
    }

    private function completeSupportSummary(): WhatsAppFlightRequest
    {
        return $this->supportFlightAt('SUPPORT_SHOW_SUMMARY', [
            'reason' => 'PAYMENT',
            'reference' => 'COT-123',
            'description' => 'No puedo completar el pago.',
            'priority' => 'NORMAL',
            'comments' => null,
        ]);
    }

    /** @param array<string, mixed> $context */
    private function supportFlightAt(string $state, array $context): WhatsAppFlightRequest
    {
        return WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()
                ->for(WhatsAppContact::factory()->state(['phone_number' => $this->phoneNumber()]), 'contact')
                ->state([
                    'state' => $state,
                    'metadata' => [
                        'active_section' => 'SUPPORT',
                        'section_context' => ['SUPPORT' => $context],
                    ],
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

    private function syncState(WhatsAppFlightRequest $flight, string $state): void
    {
        $flight->conversation()->update(['state' => $state]);
    }

    private function process(string $id, string $body, string $from): void
    {
        $job = new ProcessWhatsAppMessage([
            'message' => ['id' => $id, 'from' => $from, 'type' => 'text', 'text' => ['body' => $body]],
            'contact' => ['profile' => ['name' => 'Juan Pérez']],
        ]);
        $job->handle(app(WhatsAppConversationService::class), app(WhatsAppMessageService::class), app(WhatsAppChatbotService::class), app(WhatsAppService::class));
    }

    private function phoneNumber(): string
    {
        $this->phoneSequence++;

        return '521553456'.str_pad((string) $this->phoneSequence, 4, '0', STR_PAD_LEFT);
    }
}
