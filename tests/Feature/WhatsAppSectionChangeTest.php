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
use App\Services\WhatsApp\WhatsAppChatbotService;
use App\Services\WhatsApp\WhatsAppConversationService;
use App\Services\WhatsApp\WhatsAppMessageService;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppSectionChangeTest extends TestCase
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

    public function test_parts_to_flight_requires_confirmation_and_keeps_parts_active(): void
    {
        $flight = $this->sectionFlight('PARTS_ASK_QUANTITY', 'PARTS', ['PARTS' => ['part_number' => 'ABC']]);

        $result = $this->answer($flight, 'quiero cotizar un vuelo');

        $metadata = $flight->conversation->refresh()->metadata;
        $this->assertSame('CONFIRM_SECTION_CHANGE', $result['state']);
        $this->assertSame('PARTS', $metadata['active_section']);
        $this->assertSame('PARTS', $metadata['pending_section_change']['from_section']);
        $this->assertSame('FLIGHT', $metadata['pending_section_change']['to_section']);
        $this->assertStringContainsString('¿Deseas cambiar a Cotización de vuelo?', $result['message']);
    }

    public function test_parts_to_flight_confirmed_starts_flight_and_clears_parts_context(): void
    {
        $flight = $this->pendingChangeFlight('PARTS', 'PARTS_ASK_QUANTITY', 'FLIGHT', ['PARTS' => ['part_number' => 'ABC']]);

        $result = $this->answer($flight, '1');

        $metadata = $flight->conversation->refresh()->metadata;
        $this->assertSame('ASK_ORIGIN', $result['state']);
        $this->assertSame('FLIGHT', $metadata['active_section']);
        $this->assertArrayNotHasKey('pending_section_change', $metadata);
        $this->assertArrayNotHasKey('section_context', $metadata);
        $this->assertStringContainsString('¿Desde qué ciudad', $result['message']);
    }

    public function test_parts_to_flight_rejected_restores_parts(): void
    {
        $flight = $this->pendingChangeFlight('PARTS', 'PARTS_ASK_QUANTITY', 'FLIGHT', ['PARTS' => ['part_number' => 'ABC']]);

        $result = $this->answer($flight, '2');

        $metadata = $flight->conversation->refresh()->metadata;
        $this->assertSame('PARTS_ASK_QUANTITY', $result['state']);
        $this->assertSame('PARTS', $metadata['active_section']);
        $this->assertSame('ABC', $metadata['section_context']['PARTS']['part_number']);
        $this->assertArrayNotHasKey('pending_section_change', $metadata);
        $this->assertStringContainsString('Continuamos con Partes y refacciones.', $result['message']);
    }

    public function test_engines_support_info_and_parts_confirmed_transitions(): void
    {
        $engines = $this->sectionFlight('ENGINE_ASK_SERVICE_TYPE', 'ENGINES', ['ENGINES' => ['engine_model' => 'PW305A']]);
        $support = $this->answer($engines, 'quiero ir a soporte');
        $this->assertSame('CONFIRM_SECTION_CHANGE', $support['state']);
        $confirmedSupport = $this->answer($engines, '1');
        $this->assertSame('SUPPORT_ASK_REASON', $confirmedSupport['state']);
        $this->assertSame('SUPPORT', $engines->conversation->refresh()->metadata['active_section']);

        $supportFlight = $this->sectionFlight('SUPPORT_ASK_PRIORITY', 'SUPPORT', ['SUPPORT' => ['reason' => 'PAYMENT']]);
        $this->answer($supportFlight, 'quiero informacion');
        $confirmedInfo = $this->answer($supportFlight, 'sí');
        $this->assertSame('INFO_MENU', $confirmedInfo['state']);
        $this->assertSame('INFO', $supportFlight->conversation->refresh()->metadata['active_section']);

        $infoFlight = $this->sectionFlight('INFO_FAQ', 'INFO');
        $this->answer($infoFlight, 'quiero cambiar a partes');
        $confirmedParts = $this->answer($infoFlight, 'cambiar');
        $this->assertSame('PARTS_ASK_PART_NUMBER', $confirmedParts['state']);
        $this->assertSame('PARTS', $infoFlight->conversation->refresh()->metadata['active_section']);
    }

    public function test_same_section_numbers_and_ambiguous_text_do_not_trigger_change(): void
    {
        $parts = $this->sectionFlight('PARTS_ASK_QUANTITY', 'PARTS', ['PARTS' => ['part_number' => 'ABC']]);
        $same = $this->answer($parts, 'partes');
        $this->assertSame('PARTS_ASK_QUANTITY', $same['state']);
        $this->assertArrayNotHasKey('pending_section_change', $parts->conversation->refresh()->metadata);

        $engine = $this->sectionFlight('ENGINE_ASK_CONDITION', 'ENGINES', ['ENGINES' => ['engine_model' => 'PW305A']]);
        $number = $this->answer($engine, '3');
        $this->assertSame('ENGINE_ASK_SERVICE_TYPE', $number['state']);
        $this->assertSame('SERVICEABLE', $engine->conversation->refresh()->metadata['section_context']['ENGINES']['condition']);

        $support = $this->sectionFlight('SUPPORT_ASK_DESCRIPTION', 'SUPPORT', ['SUPPORT' => ['reason' => 'OTHER']]);
        $ambiguous = $this->answer($support, 'tengo un problema con una pieza');
        $this->assertSame('SUPPORT_ASK_PRIORITY', $ambiguous['state']);
        $this->assertSame('SUPPORT', $support->conversation->refresh()->metadata['active_section']);
    }

    public function test_commands_and_invalid_replies_during_confirmation(): void
    {
        $invalid = $this->pendingChangeFlight('PARTS', 'PARTS_ASK_QUANTITY', 'FLIGHT', ['PARTS' => ['part_number' => 'ABC']]);
        $invalidResult = $this->answer($invalid, 'quiero motores');
        $this->assertSame('CONFIRM_SECTION_CHANGE', $invalidResult['state']);
        $this->assertSame('FLIGHT', $invalid->conversation->refresh()->metadata['pending_section_change']['to_section']);

        $menu = $this->pendingChangeFlight('PARTS', 'PARTS_ASK_QUANTITY', 'FLIGHT', ['PARTS' => ['part_number' => 'ABC']]);
        $menuResult = $this->answer($menu, 'menu');
        $this->assertSame('MAIN_MENU', $menuResult['state']);
        $this->assertNull($menu->conversation->refresh()->metadata);

        $cancel = $this->pendingChangeFlight('PARTS', 'PARTS_ASK_QUANTITY', 'FLIGHT', ['PARTS' => ['part_number' => 'ABC']]);
        $cancelResult = $this->answer($cancel, 'cancelar');
        $this->assertSame('MAIN_MENU', $cancelResult['state']);
        $this->assertArrayNotHasKey('pending_section_change', $cancel->conversation->refresh()->metadata ?? []);

        $advisor = $this->pendingChangeFlight('PARTS', 'PARTS_ASK_QUANTITY', 'FLIGHT', ['PARTS' => ['part_number' => 'ABC']]);
        $advisorResult = app(WhatsAppChatbotService::class)->handleIncomingMessage($advisor->conversation, $advisor->refresh(), 'asesor');
        app(WhatsAppConversationService::class)->transferToHuman($advisor->conversation);
        $this->assertSame('TRANSFER_TO_HUMAN', $advisorResult['state']);
        $this->assertSame('PARTS', $advisor->conversation->refresh()->metadata['active_section_before_transfer']);
        $this->assertArrayNotHasKey('pending_section_change', $advisor->conversation->metadata);
    }

    public function test_context_cleanup_and_persisted_records_are_not_deleted(): void
    {
        $parts = $this->pendingChangeFlight('PARTS', 'PARTS_ASK_QUANTITY', 'INFO', ['PARTS' => ['part_number' => 'ABC']]);
        PartRequest::query()->create([
            'whats_app_conversation_id' => $parts->conversation->id,
            'whats_app_contact_id' => $parts->conversation->whats_app_contact_id,
            'part_number' => 'SAVED',
            'quantity' => 1,
            'condition' => 'SERVICEABLE',
            'status' => PartRequest::StatusNueva,
        ]);
        $this->answer($parts, '1');
        $this->assertDatabaseCount('parts_requests', 1);
        $this->assertArrayNotHasKey('section_context', $parts->conversation->refresh()->metadata);

        $engine = $this->pendingChangeFlight('ENGINES', 'ENGINE_ASK_SERVICE_TYPE', 'INFO', ['ENGINES' => ['engine_model' => 'PW305A']]);
        EngineRequest::query()->create([
            'whats_app_conversation_id' => $engine->conversation->id,
            'whats_app_contact_id' => $engine->conversation->whats_app_contact_id,
            'engine_model' => 'PW305A',
            'condition' => 'SERVICEABLE',
            'service_type' => 'SELL',
            'status' => EngineRequest::StatusNueva,
        ]);
        $this->answer($engine, '1');
        $this->assertDatabaseCount('engine_requests', 1);
        $this->assertArrayNotHasKey('section_context', $engine->conversation->refresh()->metadata);

        $support = $this->pendingChangeFlight('SUPPORT', 'SUPPORT_ASK_PRIORITY', 'INFO', ['SUPPORT' => ['reason' => 'PAYMENT']]);
        SupportRequest::query()->create([
            'whats_app_conversation_id' => $support->conversation->id,
            'whats_app_contact_id' => $support->conversation->whats_app_contact_id,
            'reason' => 'PAYMENT',
            'description' => 'Ayuda',
            'priority' => 'NORMAL',
            'status' => SupportRequest::StatusNueva,
        ]);
        $this->answer($support, '1');
        $this->assertDatabaseCount('support_requests', 1);
        $this->assertArrayNotHasKey('section_context', $support->conversation->refresh()->metadata);
    }

    public function test_flight_abandon_uses_existing_cancel_semantics(): void
    {
        $flight = $this->sectionFlight('ASK_DESTINATION', 'FLIGHT');
        $flight->update(['origin' => 'Toluca', 'status' => 'collecting']);

        $this->answer($flight, 'quiero cambiar a partes');
        $result = $this->answer($flight, '1');

        $this->assertSame('PARTS_ASK_PART_NUMBER', $result['state']);
        $this->assertSame('cancelled', $flight->refresh()->status);
        $this->assertSame('Toluca', $flight->origin);
    }

    public function test_duplicate_message_id_does_not_process_section_change_twice(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*/123/messages' => Http::response(['messages' => [['id' => 'out.section-change']]])]);
        $flight = $this->sectionFlight('PARTS_ASK_QUANTITY', 'PARTS', ['PARTS' => ['part_number' => 'ABC']]);

        $this->process('in.section-change', 'quiero cotizar un vuelo', $flight->conversation->contact->phone_number);
        $this->process('in.section-change', 'quiero cotizar un vuelo', $flight->conversation->contact->phone_number);

        $metadata = $flight->conversation->refresh()->metadata;
        $this->assertSame('CONFIRM_SECTION_CHANGE', $flight->conversation->state);
        $this->assertSame('FLIGHT', $metadata['pending_section_change']['to_section']);
        $this->assertNotNull(WhatsAppMessage::query()->where('message_id', 'in.section-change')->sole()->processed_at);
        Http::assertSentCount(1);
    }

    /** @param array<string, mixed> $sectionContext */
    private function pendingChangeFlight(string $fromSection, string $fromState, string $toSection, array $sectionContext = []): WhatsAppFlightRequest
    {
        $metadata = [
            'active_section' => $fromSection,
            'pending_section_change' => [
                'from_section' => $fromSection,
                'from_state' => $fromState,
                'to_section' => $toSection,
                'requested_at' => now()->toJSON(),
            ],
        ];

        if ($sectionContext !== []) {
            $metadata['section_context'] = $sectionContext;
        }

        return WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()
                ->for(WhatsAppContact::factory()->state(['phone_number' => $this->phoneNumber()]), 'contact')
                ->state(['state' => 'CONFIRM_SECTION_CHANGE', 'metadata' => $metadata]), 'conversation')
            ->create();
    }

    /** @param array<string, mixed> $sectionContext */
    private function sectionFlight(string $state, string $section, array $sectionContext = []): WhatsAppFlightRequest
    {
        $metadata = ['active_section' => $section];

        if ($sectionContext !== []) {
            $metadata['section_context'] = $sectionContext;
        }

        return WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()
                ->for(WhatsAppContact::factory()->state(['phone_number' => $this->phoneNumber()]), 'contact')
                ->state(['state' => $state, 'metadata' => $metadata]), 'conversation')
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

        return '521556789'.str_pad((string) $this->phoneSequence, 4, '0', STR_PAD_LEFT);
    }
}
