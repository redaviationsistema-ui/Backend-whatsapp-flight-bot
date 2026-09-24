<?php

namespace Tests\Feature;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\AdvisorRequest;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppFlightRequest;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\Flows\EngineFlowHandler;
use App\Services\WhatsApp\Flows\FlightQuoteFlowHandler;
use App\Services\WhatsApp\Flows\InformationFlowHandler;
use App\Services\WhatsApp\Flows\PartsFlowHandler;
use App\Services\WhatsApp\Flows\SupportFlowHandler;
use App\Services\WhatsApp\WhatsAppChatbotService;
use App\Services\WhatsApp\WhatsAppConversationService;
use App\Services\WhatsApp\WhatsAppMessageService;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppAdvisorFlowTest extends TestCase
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

    public function test_main_menu_option_six_enters_advisor_flow(): void
    {
        $flight = $this->flightInMenu();

        $result = $this->answer($flight, '6');

        $this->assertSame('ADVISOR_ASK_REASON', $result['state']);
        $this->assertSame('ADVISOR', $flight->conversation->refresh()->metadata['active_section']);
        $this->assertStringContainsString('Hablar con un asesor', $result['message']);
    }

    public function test_advisor_text_from_main_menu_enters_advisor_flow(): void
    {
        $flight = $this->flightInMenu();

        $result = $this->answer($flight, 'asesor');

        $this->assertSame('ADVISOR_ASK_REASON', $result['state']);
        $this->assertSame('ADVISOR', $flight->conversation->refresh()->metadata['active_section']);
    }

    public function test_advisor_entry_does_not_execute_other_flow_handlers(): void
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
        $this->mock(InformationFlowHandler::class, function ($mock): void {
            $mock->shouldNotReceive('handle');
        });

        $result = app(WhatsAppChatbotService::class)->handleIncomingMessage($flight->conversation, $flight, '6');

        $this->assertSame('ADVISOR_ASK_REASON', $result['state']);
        $this->assertSame('ADVISOR', $flight->conversation->refresh()->metadata['active_section']);
    }

    public function test_reason_options_reference_comments_and_summary(): void
    {
        $flight = $this->flightInMenu();
        $this->answer($flight, '6');
        $this->syncState($flight, 'ADVISOR_ASK_REASON');
        $this->answer($flight, '1');
        $this->answer($flight, 'COT-123');
        $result = $this->answer($flight, 'Quiero revisar disponibilidad.');

        $context = $flight->conversation->refresh()->metadata['section_context']['ADVISOR'];
        $this->assertSame('FLIGHT_QUOTE', $context['reason']);
        $this->assertSame('COT-123', $context['reference']);
        $this->assertSame('Quiero revisar disponibilidad.', $context['comments']);
        $this->assertSame('ADVISOR_SHOW_SUMMARY', $result['state']);
        $this->assertStringContainsString('Motivo: Cotización de vuelo', $result['message']);
        $this->assertStringContainsString('Referencia: COT-123', $result['message']);
        $this->assertStringContainsString('Comentarios: Quiero revisar disponibilidad.', $result['message']);

        $flight = $this->advisorFlightAt('ADVISOR_ASK_REASON', []);
        $this->answer($flight, '5');

        $this->assertSame('PARTS', $flight->conversation->refresh()->metadata['section_context']['ADVISOR']['reason']);
    }

    public function test_reference_no_is_null_and_comments_are_required(): void
    {
        $flight = $this->advisorFlightAt('ADVISOR_ASK_REFERENCE', ['reason' => 'FLIGHT_QUOTE']);

        $reference = $this->answer($flight, 'no');
        $invalidComments = $this->answer($flight, '   ');

        $this->assertSame('ADVISOR_ASK_COMMENTS', $reference['state']);
        $this->assertNull($flight->conversation->refresh()->metadata['section_context']['ADVISOR']['reference']);
        $this->assertSame('ADVISOR_ASK_COMMENTS', $invalidComments['state']);
        $this->assertArrayNotHasKey('comments', $flight->conversation->refresh()->metadata['section_context']['ADVISOR']);
    }

    public function test_edit_updates_selected_field(): void
    {
        $flight = $this->completeAdvisorSummary();

        $this->answer($flight, '2');
        $this->answer($flight, '2');
        $result = $this->answer($flight, 'no');

        $context = $flight->conversation->refresh()->metadata['section_context']['ADVISOR'];
        $this->assertSame('ADVISOR_SHOW_SUMMARY', $result['state']);
        $this->assertSame('FLIGHT_QUOTE', $context['reason']);
        $this->assertNull($context['reference']);
        $this->assertSame('Quiero revisar disponibilidad.', $context['comments']);
    }

    public function test_confirm_creates_advisor_request_and_transfers(): void
    {
        $flight = $this->completeAdvisorSummary();

        $result = $this->answer($flight, '1');

        $this->assertSame('TRANSFER_TO_HUMAN', $result['state']);
        $advisorRequest = AdvisorRequest::query()->sole();
        $this->assertSame($flight->conversation->id, $advisorRequest->whats_app_conversation_id);
        $this->assertSame($flight->conversation->whats_app_contact_id, $advisorRequest->whats_app_contact_id);
        $this->assertSame('FLIGHT_QUOTE', $advisorRequest->reason);
        $this->assertSame('COT-123', $advisorRequest->reference);
        $this->assertSame('NUEVA', $advisorRequest->status);
        $this->assertNotNull($advisorRequest->transferred_at);
        $this->assertSame('TRANSFER_TO_HUMAN', $flight->conversation->refresh()->state);
        $this->assertSame('ADVISOR', $flight->conversation->metadata['active_section_before_transfer']);
        $this->assertSame('ADVISOR_COMPLETED', $flight->conversation->metadata['bot_state_before_transfer']);
        $this->assertNotNull($flight->conversation->transferred_to_human_at);
    }

    public function test_duplicate_confirm_message_does_not_duplicate_request_or_reply(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*/123/messages' => Http::response(['messages' => [['id' => 'out.advisor']]])]);
        $flight = $this->completeAdvisorSummary();

        $this->process('in.advisor-confirm', '1', $flight->conversation->contact->phone_number);
        $transferredAt = $flight->conversation->refresh()->transferred_to_human_at?->toJSON();
        $this->process('in.advisor-confirm', '1', $flight->conversation->contact->phone_number);

        $this->assertDatabaseCount('advisor_requests', 1);
        $this->assertSame('TRANSFER_TO_HUMAN', $flight->conversation->refresh()->state);
        $this->assertSame($transferredAt, $flight->conversation->transferred_to_human_at?->toJSON());
        $this->assertNotNull(WhatsAppMessage::query()->where('message_id', 'in.advisor-confirm')->sole()->processed_at);
        Http::assertSentCount(1);
    }

    public function test_menu_and_cancel_clear_advisor_context_without_creating_request(): void
    {
        $flight = $this->advisorFlightAt('ADVISOR_ASK_REFERENCE', ['reason' => 'FLIGHT_QUOTE']);

        $menu = $this->answer($flight, 'menu');

        $this->assertSame('MAIN_MENU', $menu['state']);
        $this->assertNull($flight->conversation->refresh()->metadata);

        $flight = $this->advisorFlightAt('ADVISOR_ASK_REFERENCE', ['reason' => 'FLIGHT_QUOTE']);
        $cancel = $this->answer($flight, 'cancelar');

        $this->assertSame('MAIN_MENU', $cancel['state']);
        $this->assertStringContainsString('Solicitud de asesor cancelada.', $cancel['message']);
        $this->assertDatabaseCount('advisor_requests', 0);
    }

    public function test_advisor_from_active_sections_keeps_direct_transfer(): void
    {
        foreach ($this->activeSectionCases() as [$state, $section, $context]) {
            $flight = $this->flightAt($state, $section, $context);

            $result = app(WhatsAppChatbotService::class)->handleIncomingMessage($flight->conversation, $flight->refresh(), 'asesor');
            app(WhatsAppConversationService::class)->transferToHuman($flight->conversation);

            $this->assertSame('TRANSFER_TO_HUMAN', $result['state']);
            $this->assertSame($state, $flight->conversation->refresh()->metadata['bot_state_before_transfer']);
            $this->assertSame($section, $flight->conversation->metadata['active_section_before_transfer']);
            $this->assertDatabaseCount('advisor_requests', 0);
        }
    }

    public function test_return_to_bot_restores_previous_section_but_advisor_returns_to_menu(): void
    {
        $flight = $this->flightAt('PARTS_ASK_QUANTITY', 'PARTS', ['PARTS' => ['part_number' => 'ABC']]);
        $conversationService = app(WhatsAppConversationService::class);

        app(WhatsAppChatbotService::class)->handleIncomingMessage($flight->conversation, $flight->refresh(), 'asesor');
        $conversationService->transferToHuman($flight->conversation);
        $conversationService->returnToBot($flight->conversation->refresh());

        $this->assertSame('PARTS_ASK_QUANTITY', $flight->conversation->refresh()->state);
        $this->assertSame('PARTS', $flight->conversation->metadata['active_section']);

        $flight = $this->completeAdvisorSummary();
        $this->answer($flight, '1');
        $conversationService->returnToBot($flight->conversation->refresh());

        $this->assertSame('MAIN_MENU', $flight->conversation->refresh()->state);
        $this->assertNull($flight->conversation->metadata);
    }

    private function completeAdvisorSummary(): WhatsAppFlightRequest
    {
        return $this->advisorFlightAt('ADVISOR_SHOW_SUMMARY', [
            'reason' => 'FLIGHT_QUOTE',
            'reference' => 'COT-123',
            'comments' => 'Quiero revisar disponibilidad.',
        ]);
    }

    /** @param array<string, mixed> $context */
    private function advisorFlightAt(string $state, array $context): WhatsAppFlightRequest
    {
        return WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()
                ->for(WhatsAppContact::factory()->state(['phone_number' => $this->phoneNumber()]), 'contact')
                ->state([
                    'state' => $state,
                    'metadata' => [
                        'active_section' => 'ADVISOR',
                        'section_context' => ['ADVISOR' => $context],
                    ],
                ]), 'conversation')
            ->create();
    }

    /** @param array<string, mixed> $context */
    private function flightAt(string $state, string $section, array $context = []): WhatsAppFlightRequest
    {
        $metadata = ['active_section' => $section];

        if ($context !== []) {
            $metadata['section_context'] = $context;
        }

        return WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()
                ->for(WhatsAppContact::factory()->state(['phone_number' => $this->phoneNumber()]), 'contact')
                ->state(['state' => $state, 'metadata' => $metadata]), 'conversation')
            ->create();
    }

    private function flightInMenu(): WhatsAppFlightRequest
    {
        return WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()
                ->for(WhatsAppContact::factory()->state(['phone_number' => $this->phoneNumber()]), 'contact')
                ->state(['state' => 'MAIN_MENU']), 'conversation')
            ->create();
    }

    /** @return array<int, array{0:string,1:string,2:array<string, mixed>}> */
    private function activeSectionCases(): array
    {
        return [
            ['SELECT_AIRCRAFT', 'FLIGHT', []],
            ['PARTS_ASK_QUANTITY', 'PARTS', ['PARTS' => ['part_number' => 'ABC']]],
            ['ENGINE_ASK_SERVICE_TYPE', 'ENGINES', ['ENGINES' => ['engine_model' => 'PW305A']]],
            ['SUPPORT_ASK_PRIORITY', 'SUPPORT', ['SUPPORT' => ['reason' => 'PAYMENT']]],
            ['INFO_FAQ', 'INFO', []],
        ];
    }

    /** @return array{state:string,message:string} */
    private function answer(WhatsAppFlightRequest $flight, string $answer): array
    {
        $conversation = $flight->conversation()->firstOrFail();
        $result = app(WhatsAppChatbotService::class)->handleIncomingMessage($conversation, $flight->refresh(), $answer);

        if ($conversation->refresh()->state !== 'TRANSFER_TO_HUMAN') {
            $conversation->update(['state' => $result['state']]);
        }

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

        return '521555678'.str_pad((string) $this->phoneSequence, 4, '0', STR_PAD_LEFT);
    }
}
