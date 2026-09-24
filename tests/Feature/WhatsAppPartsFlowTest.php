<?php

namespace Tests\Feature;

use App\Jobs\ProcessWhatsAppMessage;
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

class WhatsAppPartsFlowTest extends TestCase
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

    public function test_main_menu_option_two_enters_parts_flow(): void
    {
        $flight = $this->flightInMenu();

        $result = $this->answer($flight, '2');

        $this->assertSame('PARTS_ASK_PART_NUMBER', $result['state']);
        $this->assertSame('PARTS', $flight->conversation->refresh()->metadata['active_section']);
        $this->assertStringContainsString('número de parte', $result['message']);
    }

    public function test_parts_flow_captures_summary_and_does_not_touch_flight_request(): void
    {
        $flight = $this->flightInMenu();
        $this->answer($flight, '2');
        $this->syncState($flight, 'PARTS_ASK_PART_NUMBER');
        $this->answer($flight, ' 109-0900-76-2a05 ');
        $this->answer($flight, 'EDU');
        $this->answer($flight, '1');
        $this->answer($flight, 'serviceable');
        $result = $this->answer($flight, 'no');

        $context = $flight->conversation->refresh()->metadata['section_context']['PARTS'];
        $this->assertSame('109-0900-76-2A05', $context['part_number']);
        $this->assertSame('EDU', $context['description']);
        $this->assertSame(1, $context['quantity']);
        $this->assertSame('SERVICEABLE', $context['condition']);
        $this->assertNull($context['comments']);
        $this->assertSame('PARTS_SHOW_SUMMARY', $result['state']);
        $this->assertStringContainsString('P/N: 109-0900-76-2A05', $result['message']);
        $this->assertStringContainsString('Condición: Serviceable', $result['message']);
        $this->assertNull($flight->refresh()->origin);
        $this->assertNull($flight->destination);
        $this->assertNull($flight->selected_aircraft_id);
        $this->assertNull($flight->official_quote_payload);
    }

    public function test_quantity_rejects_zero_and_accepts_words(): void
    {
        $flight = $this->partsFlightAt('PARTS_ASK_QUANTITY', ['part_number' => 'ABC', 'description' => null]);

        $invalid = $this->answer($flight, '0');
        $valid = $this->answer($flight, 'dos');

        $this->assertSame('PARTS_ASK_QUANTITY', $invalid['state']);
        $this->assertSame('PARTS_ASK_CONDITION', $valid['state']);
        $this->assertSame(2, $flight->conversation->refresh()->metadata['section_context']['PARTS']['quantity']);
    }

    public function test_condition_options_are_normalized(): void
    {
        $flight = $this->partsFlightAt('PARTS_ASK_CONDITION', ['part_number' => 'ABC', 'quantity' => 1]);

        $result = $this->answer($flight, '1');

        $this->assertSame('PARTS_ASK_COMMENTS', $result['state']);
        $this->assertSame('NEW', $flight->conversation->refresh()->metadata['section_context']['PARTS']['condition']);

        $flight = $this->partsFlightAt('PARTS_ASK_CONDITION', ['part_number' => 'ABC', 'quantity' => 1]);
        $this->answer($flight, 'serviceable');

        $this->assertSame('SERVICEABLE', $flight->conversation->refresh()->metadata['section_context']['PARTS']['condition']);
    }

    public function test_confirm_creates_part_request_once(): void
    {
        $flight = $this->completePartsSummary();

        $result = $this->answer($flight, '1');

        $this->assertSame('PARTS_COMPLETED', $result['state']);
        $partRequest = PartRequest::query()->sole();
        $this->assertSame($flight->conversation->id, $partRequest->whats_app_conversation_id);
        $this->assertSame($flight->conversation->whats_app_contact_id, $partRequest->whats_app_contact_id);
        $this->assertSame('109-0900-76-2A05', $partRequest->part_number);
        $this->assertSame('NUEVA', $partRequest->status);
        $this->assertSame('PARTS', $flight->conversation->refresh()->metadata['active_section']);
    }

    public function test_duplicate_confirm_message_does_not_duplicate_part_request(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*/123/messages' => Http::response(['messages' => [['id' => 'out.parts']]])]);
        $flight = $this->completePartsSummary();

        $this->process('in.parts-confirm', '1', $flight->conversation->contact->phone_number);
        $this->process('in.parts-confirm', '1', $flight->conversation->contact->phone_number);

        $this->assertDatabaseCount('parts_requests', 1);
        $this->assertSame('PARTS_COMPLETED', $flight->conversation->refresh()->state);
        $this->assertNotNull(WhatsAppMessage::query()->where('message_id', 'in.parts-confirm')->sole()->processed_at);
        Http::assertSentCount(1);
    }

    public function test_menu_clears_parts_context_and_cancel_does_not_create_request(): void
    {
        $flight = $this->partsFlightAt('PARTS_ASK_DESCRIPTION', ['part_number' => 'ABC']);

        $menu = $this->answer($flight, 'menu');

        $this->assertSame('MAIN_MENU', $menu['state']);
        $this->assertNull($flight->conversation->refresh()->metadata);

        $flight = $this->partsFlightAt('PARTS_ASK_DESCRIPTION', ['part_number' => 'ABC']);
        $cancel = $this->answer($flight, 'cancelar');

        $this->assertSame('MAIN_MENU', $cancel['state']);
        $this->assertStringContainsString('Solicitud de parte cancelada.', $cancel['message']);
        $this->assertDatabaseCount('parts_requests', 0);
    }

    public function test_advisor_and_return_to_bot_restore_parts_context(): void
    {
        $flight = $this->partsFlightAt('PARTS_ASK_QUANTITY', ['part_number' => 'ABC']);
        $conversationService = app(WhatsAppConversationService::class);

        $conversation = $flight->conversation()->firstOrFail();
        $result = app(WhatsAppChatbotService::class)->handleIncomingMessage($conversation, $flight->refresh(), 'asesor');
        $conversationService->transferToHuman($conversation);

        $this->assertSame('TRANSFER_TO_HUMAN', $result['state']);
        $this->assertSame('PARTS', $flight->conversation->refresh()->metadata['active_section_before_transfer']);
        $this->assertSame('PARTS_ASK_QUANTITY', $flight->conversation->metadata['bot_state_before_transfer']);

        $conversationService->returnToBot($flight->conversation->refresh());

        $this->assertSame('PARTS_ASK_QUANTITY', $flight->conversation->refresh()->state);
        $this->assertSame('PARTS', $flight->conversation->metadata['active_section']);
        $this->assertSame('ABC', $flight->conversation->metadata['section_context']['PARTS']['part_number']);
    }

    private function completePartsSummary(): WhatsAppFlightRequest
    {
        return $this->partsFlightAt('PARTS_SHOW_SUMMARY', [
            'part_number' => '109-0900-76-2A05',
            'description' => 'EDU',
            'quantity' => 1,
            'condition' => 'SERVICEABLE',
            'comments' => null,
        ]);
    }

    /** @param array<string, mixed> $context */
    private function partsFlightAt(string $state, array $context): WhatsAppFlightRequest
    {
        return WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()
                ->for(WhatsAppContact::factory()->state(['phone_number' => $this->phoneNumber()]), 'contact')
                ->state([
                    'state' => $state,
                    'metadata' => [
                        'active_section' => 'PARTS',
                        'section_context' => ['PARTS' => $context],
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

        return '521551234'.str_pad((string) $this->phoneSequence, 4, '0', STR_PAD_LEFT);
    }
}
