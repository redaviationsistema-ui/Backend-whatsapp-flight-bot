<?php

namespace Tests\Feature;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\EngineRequest;
use App\Models\PartRequest;
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
use Tests\TestCase;

class WhatsAppEnginesFlowTest extends TestCase
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

    public function test_main_menu_option_three_enters_engines_flow(): void
    {
        $flight = $this->flightInMenu();

        $result = $this->answer($flight, '3');

        $this->assertSame('ENGINE_ASK_MODEL', $result['state']);
        $this->assertSame('ENGINES', $flight->conversation->refresh()->metadata['active_section']);
        $this->assertStringContainsString('modelo de motor', $result['message']);
    }

    public function test_engines_flow_captures_summary_and_does_not_touch_other_modules(): void
    {
        $flight = $this->flightInMenu();
        $this->answer($flight, '3');
        $this->syncState($flight, 'ENGINE_ASK_MODEL');
        $this->answer($flight, ' tfe731-3ar-2b ');
        $this->answer($flight, 'no');
        $this->answer($flight, ' p-85286 ');
        $this->answer($flight, '3');
        $this->answer($flight, 'venta');
        $result = $this->answer($flight, 'Motor disponible para venta.');

        $context = $flight->conversation->refresh()->metadata['section_context']['ENGINES'];
        $this->assertSame('TFE731-3AR-2B', $context['engine_model']);
        $this->assertNull($context['part_number']);
        $this->assertSame('P-85286', $context['serial_number']);
        $this->assertSame('SERVICEABLE', $context['condition']);
        $this->assertSame('SELL', $context['service_type']);
        $this->assertSame('Motor disponible para venta.', $context['comments']);
        $this->assertSame('ENGINE_SHOW_SUMMARY', $result['state']);
        $this->assertStringContainsString('Modelo: TFE731-3AR-2B', $result['message']);
        $this->assertStringContainsString('P/N: —', $result['message']);
        $this->assertStringContainsString('S/N: P-85286', $result['message']);
        $this->assertStringContainsString('Condición: Serviceable', $result['message']);
        $this->assertStringContainsString('Solicitud: Venta', $result['message']);
        $this->assertDatabaseCount('parts_requests', 0);
        $this->assertNull($flight->refresh()->origin);
        $this->assertNull($flight->destination);
        $this->assertNull($flight->selected_aircraft_id);
        $this->assertNull($flight->official_quote_payload);
    }

    public function test_optional_part_number_and_serial_number_accept_unknown_values(): void
    {
        $flight = $this->enginesFlightAt('ENGINE_ASK_PART_NUMBER', ['engine_model' => 'JT15D-5']);

        $partNumber = $this->answer($flight, 'no tengo');
        $serialNumber = $this->answer($flight, 'desconocido');

        $context = $flight->conversation->refresh()->metadata['section_context']['ENGINES'];
        $this->assertSame('ENGINE_ASK_SERIAL_NUMBER', $partNumber['state']);
        $this->assertSame('ENGINE_ASK_CONDITION', $serialNumber['state']);
        $this->assertNull($context['part_number']);
        $this->assertNull($context['serial_number']);
    }

    public function test_condition_options_are_normalized(): void
    {
        $flight = $this->enginesFlightAt('ENGINE_ASK_CONDITION', ['engine_model' => 'JT15D-5']);

        $result = $this->answer($flight, '3');

        $this->assertSame('ENGINE_ASK_SERVICE_TYPE', $result['state']);
        $this->assertSame('SERVICEABLE', $flight->conversation->refresh()->metadata['section_context']['ENGINES']['condition']);

        $flight = $this->enginesFlightAt('ENGINE_ASK_CONDITION', ['engine_model' => 'JT15D-5']);
        $this->answer($flight, 'sv');

        $this->assertSame('SERVICEABLE', $flight->conversation->refresh()->metadata['section_context']['ENGINES']['condition']);
    }

    public function test_service_type_options_are_normalized(): void
    {
        $flight = $this->enginesFlightAt('ENGINE_ASK_SERVICE_TYPE', ['engine_model' => 'JT15D-5', 'condition' => 'SERVICEABLE']);

        $result = $this->answer($flight, '2');

        $this->assertSame('ENGINE_ASK_COMMENTS', $result['state']);
        $this->assertSame('SELL', $flight->conversation->refresh()->metadata['section_context']['ENGINES']['service_type']);

        $flight = $this->enginesFlightAt('ENGINE_ASK_SERVICE_TYPE', ['engine_model' => 'JT15D-5', 'condition' => 'SERVICEABLE']);
        $this->answer($flight, 'exchange');

        $this->assertSame('EXCHANGE', $flight->conversation->refresh()->metadata['section_context']['ENGINES']['service_type']);
    }

    public function test_confirm_creates_engine_request_once(): void
    {
        $flight = $this->completeEnginesSummary();

        $result = $this->answer($flight, '1');

        $this->assertSame('ENGINE_COMPLETED', $result['state']);
        $engineRequest = EngineRequest::query()->sole();
        $this->assertSame($flight->conversation->id, $engineRequest->whats_app_conversation_id);
        $this->assertSame($flight->conversation->whats_app_contact_id, $engineRequest->whats_app_contact_id);
        $this->assertSame('TFE731-3AR-2B', $engineRequest->engine_model);
        $this->assertNull($engineRequest->part_number);
        $this->assertSame('P-85286', $engineRequest->serial_number);
        $this->assertSame('SERVICEABLE', $engineRequest->condition);
        $this->assertSame('SELL', $engineRequest->service_type);
        $this->assertSame('NUEVA', $engineRequest->status);
        $this->assertSame('ENGINES', $flight->conversation->refresh()->metadata['active_section']);
    }

    public function test_duplicate_confirm_message_does_not_duplicate_engine_request(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*/123/messages' => Http::response(['messages' => [['id' => 'out.engines']]])]);
        $flight = $this->completeEnginesSummary();

        $this->process('in.engines-confirm', '1', $flight->conversation->contact->phone_number);
        $this->process('in.engines-confirm', '1', $flight->conversation->contact->phone_number);

        $this->assertDatabaseCount('engine_requests', 1);
        $this->assertSame('ENGINE_COMPLETED', $flight->conversation->refresh()->state);
        $this->assertNotNull(WhatsAppMessage::query()->where('message_id', 'in.engines-confirm')->sole()->processed_at);
        Http::assertSentCount(1);
    }

    public function test_menu_clears_engines_context_and_cancel_does_not_create_request(): void
    {
        $flight = $this->enginesFlightAt('ENGINE_ASK_PART_NUMBER', ['engine_model' => 'PW305A']);

        $menu = $this->answer($flight, 'menu');

        $this->assertSame('MAIN_MENU', $menu['state']);
        $this->assertNull($flight->conversation->refresh()->metadata);

        $flight = $this->enginesFlightAt('ENGINE_ASK_PART_NUMBER', ['engine_model' => 'PW305A']);
        $cancel = $this->answer($flight, 'cancelar');

        $this->assertSame('MAIN_MENU', $cancel['state']);
        $this->assertStringContainsString('Solicitud de motor cancelada.', $cancel['message']);
        $this->assertDatabaseCount('engine_requests', 0);
    }

    public function test_advisor_and_return_to_bot_restore_engines_context(): void
    {
        $flight = $this->enginesFlightAt('ENGINE_ASK_SERVICE_TYPE', ['engine_model' => 'PW305A', 'condition' => 'SERVICEABLE']);
        $conversationService = app(WhatsAppConversationService::class);

        $conversation = $flight->conversation()->firstOrFail();
        $result = app(WhatsAppChatbotService::class)->handleIncomingMessage($conversation, $flight->refresh(), 'asesor');
        $conversationService->transferToHuman($conversation);

        $this->assertSame('TRANSFER_TO_HUMAN', $result['state']);
        $this->assertSame('ENGINES', $flight->conversation->refresh()->metadata['active_section_before_transfer']);
        $this->assertSame('ENGINE_ASK_SERVICE_TYPE', $flight->conversation->metadata['bot_state_before_transfer']);

        $conversationService->returnToBot($flight->conversation->refresh());

        $this->assertSame('ENGINE_ASK_SERVICE_TYPE', $flight->conversation->refresh()->state);
        $this->assertSame('ENGINES', $flight->conversation->metadata['active_section']);
        $this->assertSame('PW305A', $flight->conversation->metadata['section_context']['ENGINES']['engine_model']);
    }

    public function test_engines_flow_does_not_modify_existing_part_request(): void
    {
        $flight = $this->completeEnginesSummary();
        $partRequest = PartRequest::query()->create([
            'whats_app_conversation_id' => $flight->conversation->id,
            'whats_app_contact_id' => $flight->conversation->whats_app_contact_id,
            'part_number' => 'ABC',
            'quantity' => 1,
            'condition' => 'SERVICEABLE',
            'status' => PartRequest::StatusNueva,
        ]);

        $this->answer($flight, '1');

        $this->assertDatabaseCount('engine_requests', 1);
        $this->assertDatabaseCount('parts_requests', 1);
        $this->assertSame('ABC', $partRequest->refresh()->part_number);
    }

    private function completeEnginesSummary(): WhatsAppFlightRequest
    {
        return $this->enginesFlightAt('ENGINE_SHOW_SUMMARY', [
            'engine_model' => 'TFE731-3AR-2B',
            'part_number' => null,
            'serial_number' => 'P-85286',
            'condition' => 'SERVICEABLE',
            'service_type' => 'SELL',
            'comments' => null,
        ]);
    }

    /** @param array<string, mixed> $context */
    private function enginesFlightAt(string $state, array $context): WhatsAppFlightRequest
    {
        return WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()
                ->for(WhatsAppContact::factory()->state(['phone_number' => $this->phoneNumber()]), 'contact')
                ->state([
                    'state' => $state,
                    'metadata' => [
                        'active_section' => 'ENGINES',
                        'section_context' => ['ENGINES' => $context],
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

        return '521552345'.str_pad((string) $this->phoneSequence, 4, '0', STR_PAD_LEFT);
    }
}
