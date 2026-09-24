<?php

namespace Tests\Feature;

use App\Models\AdvisorRequest;
use App\Models\EngineRequest;
use App\Models\PartRequest;
use App\Models\SupportRequest;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppFlightRequest;
use App\Models\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class WhatsAppAdminTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['GET', ''])]
    #[TestWith(['GET', '/{id}'])]
    #[TestWith(['GET', '/{id}/messages'])]
    #[TestWith(['POST', '/{id}/messages'])]
    #[TestWith(['POST', '/{id}/takeover'])]
    #[TestWith(['POST', '/{id}/transfer-to-human'])]
    #[TestWith(['POST', '/{id}/return-to-bot'])]
    public function test_conversation_routes_are_accessible_without_authentication(string $method, string $path): void
    {
        config(['services.whatsapp.phone_number_id' => '123', 'services.whatsapp.access_token' => 'test-token']);
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*/123/messages' => Http::response(['messages' => [['id' => 'out.public']]])]);
        $conversation = WhatsAppConversation::factory()->create();
        $path = str_replace('{id}', (string) $conversation->id, $path);

        $this->json($method, '/api/admin/whatsapp/conversations'.$path, ['body' => 'Hola'])
            ->assertSuccessful()->assertJsonPath('success', true);
        $this->assertGuest();
        if ($method === 'POST' && str_ends_with($path, '/messages')) {
            $this->assertDatabaseHas('whats_app_messages', ['message_id' => 'out.public', 'body' => 'Hola']);
            Http::assertSentCount(1);
        } else {
            Http::assertNothingSent();
        }
    }

    public function test_regular_user_can_access_conversations(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/admin/whatsapp/conversations')->assertOk()->assertJsonPath('success', true);
    }

    public function test_admin_login_rotates_session_and_logout_invalidates_it(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->getJson('/api/admin/csrf')->assertOk()->assertJsonStructure(['success', 'data' => ['csrf_token']]);
        $sessionId = session()->getId();

        $this->postJson('/api/admin/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertOk()->assertJsonPath('data.user.id', $admin->id)->assertJsonStructure(['data' => ['csrf_token']]);
        $this->assertNotSame($sessionId, session()->getId());
        $this->assertAuthenticatedAs($admin);
        $this->postJson('/api/admin/logout')->assertOk()->assertJsonPath('success', true);
        $this->assertGuest();
    }

    public function test_wrong_password_and_nonadmin_login_are_rejected(): void
    {
        $user = User::factory()->create();
        $this->postJson('/api/admin/login', ['email' => $user->email, 'password' => 'incorrect'])
            ->assertUnauthorized()->assertJsonPath('success', false);
        $this->postJson('/api/admin/login', ['email' => $user->email, 'password' => 'password'])
            ->assertForbidden()->assertJsonPath('success', false);
        $this->assertGuest();
    }

    public function test_login_attempts_are_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/admin/login', ['email' => 'unknown@example.com', 'password' => 'wrong'])->assertUnauthorized();
        }
        $this->postJson('/api/admin/login', ['email' => 'unknown@example.com', 'password' => 'wrong'])
            ->assertTooManyRequests()->assertJsonPath('success', false);
    }

    public function test_public_takeover_requires_neither_session_nor_csrf_token(): void
    {
        $conversation = WhatsAppConversation::factory()->create();
        $this->app->instance('env', 'local');

        $this->postJson('/api/admin/whatsapp/conversations/'.$conversation->id.'/takeover')
            ->assertOk()->assertJsonPath('success', true);
        $this->assertNotNull($conversation->refresh()->transferred_to_human_at);
        $this->assertSame('TRANSFER_TO_HUMAN', $conversation->state);
        $this->assertTrue($conversation->is_active);
    }

    public function test_conversations_are_paginated_by_recency_with_contact_and_latest_message(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $older = WhatsAppConversation::factory()->create(['last_message_at' => now()->subHour()]);
        $newer = WhatsAppConversation::factory()->create(['last_message_at' => now()]);
        WhatsAppMessage::factory()->for($newer, 'conversation')->create(['body' => 'Primero', 'sent_at' => now()->subMinute()]);
        $latest = WhatsAppMessage::factory()->for($newer, 'conversation')->create(['body' => 'Último', 'sent_at' => now(), 'payload' => ['private' => 'not-exposed']]);

        $response = $this->actingAs($admin)->getJson('/api/admin/whatsapp/conversations?per_page=1');

        $response->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.0.contact.phone_number', $newer->contact->phone_number)
            ->assertJsonPath('data.0.last_message.id', $latest->id)
            ->assertJsonPath('meta.total', 2)->assertJsonMissingPath('data.0.last_message.payload');
        $this->assertStringContainsString('per_page=1', $response->json('links.next'));
        $this->getJson('/api/admin/whatsapp/conversations?per_page=1&page=2')->assertJsonPath('data.0.id', $older->id);
    }

    public function test_detail_contains_flight_and_summary_without_internal_payloads(): void
    {
        $flight = WhatsAppFlightRequest::factory()->create(['origin' => 'Toluca', 'official_quote_payload' => ['internal' => true]]);
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->getJson('/api/admin/whatsapp/conversations/'.$flight->whats_app_conversation_id)
            ->assertOk()->assertJsonPath('data.flight_request.origin', 'Toluca')
            ->assertJsonMissingPath('data.flight_request.official_quote_payload')->assertJsonStructure(['summary']);
    }

    public function test_flight_requests_are_listed_for_whatsapp_admin_with_quote_summary(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $flight = WhatsAppFlightRequest::factory()->create([
            'origin' => 'TLC',
            'destination' => 'MTY',
            'departure_date' => '2026-09-24',
            'departure_time' => '12:00',
            'passengers' => 3,
            'trip_type' => 'one_way',
            'selected_aircraft_id' => '01645e8c-0a40-496e-a4ec-cbbb77061722',
            'selected_provider_id' => 201,
            'selected_match_id' => 'match-101',
            'status' => 'quoted',
            'official_quote_payload' => [
                'aircraft_name' => 'LEAR JET 31',
                'display_time' => '02:20',
                'total' => 6977,
                'currency' => 'USD',
                'pricing_breakdown' => ['total' => 6977],
            ],
        ]);

        $response = $this->actingAs($admin)->getJson('/api/admin/whatsapp/flight-requests?per_page=1');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.id', $flight->id)
            ->assertJsonPath('data.0.contact.phone_number', $flight->conversation->contact->phone_number)
            ->assertJsonPath('data.0.route', 'TLC -> MTY')
            ->assertJsonPath('data.0.aircraft_name', 'LEAR JET 31')
            ->assertJsonPath('data.0.estimated_time', '02:20')
            ->assertJsonPath('data.0.estimated_price', 6977)
            ->assertJsonPath('data.0.currency', 'USD')
            ->assertJsonPath('data.0.selected_aircraft_id', '01645e8c-0a40-496e-a4ec-cbbb77061722')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_flight_requests_can_be_filtered_and_show_full_quote_payload(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        WhatsAppFlightRequest::factory()->create([
            'origin' => 'TLC',
            'destination' => 'CUN',
            'status' => 'collecting',
            'official_quote_payload' => ['aircraft_name' => 'Other Jet'],
        ]);
        $flight = WhatsAppFlightRequest::factory()->create([
            'origin' => 'TLC',
            'destination' => 'MTY',
            'departure_date' => '2026-09-24',
            'selected_aircraft' => 'Legacy 600',
            'selected_aircraft_id' => '01645e8c-0a40-496e-a4ec-cbbb77061722',
            'status' => 'quoted',
            'official_quote_payload' => [
                'aircraft_name' => 'LEAR JET 31',
                'capacity_passengers' => 8,
                'pricing' => ['totals' => ['estimated_hhmm' => '02:20']],
                'pricing_breakdown' => ['customer_flight_cost' => 5000, 'total' => 6977],
                'estimated_total' => 6977,
                'currency' => 'USD',
            ],
        ]);

        $this->actingAs($admin)
            ->getJson('/api/admin/whatsapp/flight-requests?status=quoted&destination=MTY&aircraft=Legacy&date=2026-09-24')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $flight->id);

        $this->actingAs($admin)
            ->getJson('/api/admin/whatsapp/flight-requests/'.$flight->id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.aircraft_capacity', 8)
            ->assertJsonPath('data.estimated_time', '02:20')
            ->assertJsonPath('data.pricing_breakdown.customer_flight_cost', 5000)
            ->assertJsonPath('data.official_quote_payload.aircraft_name', 'LEAR JET 31');
    }

    public function test_whatsapp_admin_dashboard_counts_all_flow_modules(): void
    {
        $conversation = WhatsAppConversation::factory()->create(['is_active' => true, 'state' => 'TRANSFER_TO_HUMAN', 'transferred_to_human_at' => now()]);
        WhatsAppFlightRequest::factory()->for($conversation, 'conversation')->create();
        PartRequest::query()->create([
            'whats_app_conversation_id' => $conversation->id,
            'whats_app_contact_id' => $conversation->whats_app_contact_id,
            'part_number' => 'PN-100',
            'quantity' => 2,
            'condition' => 'Nueva',
            'status' => 'NUEVA',
        ]);
        EngineRequest::query()->create([
            'whats_app_conversation_id' => $conversation->id,
            'whats_app_contact_id' => $conversation->whats_app_contact_id,
            'engine_model' => 'PT6A',
            'condition' => 'Usado',
            'service_type' => 'Compra',
            'status' => 'EN_ATENCION',
        ]);
        SupportRequest::query()->create([
            'whats_app_conversation_id' => $conversation->id,
            'whats_app_contact_id' => $conversation->whats_app_contact_id,
            'reason' => 'postventa',
            'description' => 'Seguimiento',
            'priority' => 'normal',
            'status' => 'NUEVA',
        ]);
        AdvisorRequest::query()->create([
            'whats_app_conversation_id' => $conversation->id,
            'whats_app_contact_id' => $conversation->whats_app_contact_id,
            'reason' => 'asesor',
            'comments' => 'Llamar',
            'status' => 'NUEVA',
        ]);

        $this->getJson('/api/admin/whatsapp/dashboard')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.conversations.total', 1)
            ->assertJsonPath('data.conversations.human', 1)
            ->assertJsonPath('data.flight_quotes.total', 1)
            ->assertJsonPath('data.parts.total', 1)
            ->assertJsonPath('data.engines.in_progress', 1)
            ->assertJsonPath('data.support.new', 1)
            ->assertJsonPath('data.advisor.total', 1);
    }

    public function test_whatsapp_admin_request_lists_detail_and_status_update(): void
    {
        $conversation = WhatsAppConversation::factory()->create();
        $part = PartRequest::query()->create([
            'whats_app_conversation_id' => $conversation->id,
            'whats_app_contact_id' => $conversation->whats_app_contact_id,
            'part_number' => 'ABC-123',
            'description' => 'Filtro',
            'quantity' => 1,
            'condition' => 'Nueva',
            'status' => 'NUEVA',
        ]);
        PartRequest::query()->create([
            'whats_app_conversation_id' => $conversation->id,
            'whats_app_contact_id' => $conversation->whats_app_contact_id,
            'part_number' => 'ZZZ-999',
            'quantity' => 1,
            'condition' => 'Usada',
            'status' => 'CERRADA',
        ]);

        $this->getJson('/api/admin/whatsapp/parts?search=ABC&status=NUEVA')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $part->id)
            ->assertJsonPath('data.0.contact.phone_number', $conversation->contact->phone_number);

        $this->getJson('/api/admin/whatsapp/parts/'.$part->id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.part_number', 'ABC-123');

        $this->patchJson('/api/admin/whatsapp/parts/'.$part->id.'/status', ['status' => 'EN_ATENCION'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'EN_ATENCION');
        $this->assertDatabaseHas('parts_requests', ['id' => $part->id, 'status' => 'EN_ATENCION']);

        $this->patchJson('/api/admin/whatsapp/parts/'.$part->id.'/status', ['status' => 'INVALIDA'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_whatsapp_admin_lists_engines_support_and_advisor_requests(): void
    {
        $conversation = WhatsAppConversation::factory()->create();
        $engine = EngineRequest::query()->create([
            'whats_app_conversation_id' => $conversation->id,
            'whats_app_contact_id' => $conversation->whats_app_contact_id,
            'engine_model' => 'PT6A',
            'part_number' => 'PT6-01',
            'condition' => 'Usado',
            'service_type' => 'Mantenimiento',
            'status' => 'NUEVA',
        ]);
        $support = SupportRequest::query()->create([
            'whats_app_conversation_id' => $conversation->id,
            'whats_app_contact_id' => $conversation->whats_app_contact_id,
            'reason' => 'facturacion',
            'reference' => 'FAC-1',
            'description' => 'Ayuda',
            'priority' => 'alta',
            'status' => 'PENDIENTE',
        ]);
        $advisor = AdvisorRequest::query()->create([
            'whats_app_conversation_id' => $conversation->id,
            'whats_app_contact_id' => $conversation->whats_app_contact_id,
            'reason' => 'ventas',
            'reference' => 'REF-1',
            'comments' => 'Quiere asesor',
            'status' => 'NUEVA',
            'transferred_at' => now(),
        ]);

        $this->getJson('/api/admin/whatsapp/engines?service_type=Mantenimiento')
            ->assertOk()
            ->assertJsonPath('data.0.id', $engine->id)
            ->assertJsonPath('data.0.engine_model', 'PT6A');
        $this->getJson('/api/admin/whatsapp/support?priority=alta')
            ->assertOk()
            ->assertJsonPath('data.0.id', $support->id)
            ->assertJsonPath('data.0.reference', 'FAC-1');
        $this->getJson('/api/admin/whatsapp/advisor-requests?transferred=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $advisor->id)
            ->assertJsonPath('data.0.transferred_at', $advisor->transferred_at->toJSON());
    }

    public function test_global_whatsapp_history_is_paginated_without_internal_payloads(): void
    {
        $conversation = WhatsAppConversation::factory()->create();
        $message = WhatsAppMessage::factory()->for($conversation, 'conversation')->create(['body' => 'Historial', 'processing_context' => ['private' => true]]);

        $this->getJson('/api/admin/whatsapp/history?per_page=1')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.id', 'message-'.$message->id)
            ->assertJsonPath('data.0.contact.phone_number', $conversation->contact->phone_number)
            ->assertJsonMissingPath('data.0.processing_context')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_history_is_chronological_and_excludes_other_conversations(): void
    {
        $conversation = WhatsAppConversation::factory()->create();
        $latest = WhatsAppMessage::factory()->for($conversation, 'conversation')->create(['sent_at' => now(), 'direction' => 'outbound', 'status' => 'read']);
        $first = WhatsAppMessage::factory()->for($conversation, 'conversation')->create(['sent_at' => now()->subMinute()]);
        WhatsAppMessage::factory()->create();

        $this->actingAs(User::factory()->create(['is_admin' => true]))->getJson('/api/admin/whatsapp/conversations/'.$conversation->id.'/messages')
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $first->id)->assertJsonPath('data.1.id', $latest->id)
            ->assertJsonPath('data.1.status', 'read')->assertJsonMissingPath('data.0.processing_context');
    }

    public function test_admin_message_is_sent_to_contact_and_saved_with_meta_id(): void
    {
        config(['services.whatsapp.phone_number_id' => '123', 'services.whatsapp.access_token' => 'test-token']);
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*/123/messages' => Http::response(['messages' => [['id' => 'out.admin']]])]);
        $conversation = WhatsAppConversation::factory()->create();

        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->postJson('/api/admin/whatsapp/conversations/'.$conversation->id.'/messages', ['body' => 'Mensaje del administrador', 'phone_number' => 'attacker'])
            ->assertCreated()->assertJsonPath('data.message_id', 'out.admin')->assertJsonPath('data.status', 'sent');

        $this->assertDatabaseHas('whats_app_messages', ['message_id' => 'out.admin', 'direction' => 'outbound', 'body' => 'Mensaje del administrador']);
        Http::assertSent(fn ($request): bool => $request['to'] === $conversation->contact->phone_number && $request['text']['body'] === 'Mensaje del administrador');
    }

    #[TestWith([500, ['error' => ['message' => 'Meta unavailable']]])]
    #[TestWith([200, ['messages' => []]])]
    public function test_failed_or_malformed_meta_response_does_not_create_outbound(int $status, array $body): void
    {
        config(['services.whatsapp.phone_number_id' => '123', 'services.whatsapp.access_token' => 'test-token']);
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*/123/messages' => Http::response($body, $status)]);
        $conversation = WhatsAppConversation::factory()->create();

        $this->actingAs(User::factory()->create(['is_admin' => true]))->postJson('/api/admin/whatsapp/conversations/'.$conversation->id.'/messages', ['body' => 'Hola'])
            ->assertStatus(502)->assertJsonPath('success', false)->assertJsonPath('message', 'No fue posible enviar el mensaje');
        $this->assertDatabaseCount('whats_app_messages', 0);
        Http::assertSentCount(1);
    }

    #[TestWith([''])]
    #[TestWith(['   '])]
    #[TestWith([null])]
    public function test_empty_admin_message_returns_422_without_sending(?string $body): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*' => Http::response([])]);
        $conversation = WhatsAppConversation::factory()->create();
        $this->actingAs(User::factory()->create(['is_admin' => true]))->postJson('/api/admin/whatsapp/conversations/'.$conversation->id.'/messages', ['body' => $body])
            ->assertUnprocessable()->assertJsonPath('success', false)->assertJsonValidationErrors('body');
        Http::assertNothingSent();
        $this->assertDatabaseCount('whats_app_messages', 0);
    }

    public function test_takeover_and_return_restore_same_conversation_and_bot_state(): void
    {
        $conversation = WhatsAppConversation::factory()->create(['state' => 'ASK_EMAIL']);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $prefix = '/api/admin/whatsapp/conversations/'.$conversation->id;

        $this->postJson($prefix.'/takeover')->assertOk()->assertJsonPath('data.state', 'TRANSFER_TO_HUMAN')->assertJsonPath('data.is_active', true);
        $this->assertNotNull($conversation->refresh()->transferred_to_human_at);
        $this->postJson($prefix.'/takeover')->assertOk();
        $this->postJson($prefix.'/return-to-bot')->assertOk()->assertJsonPath('data.state', 'ASK_EMAIL')->assertJsonPath('data.transferred_to_human_at', null);
        $this->assertDatabaseCount('whats_app_conversations', 1);
    }

    public function test_unknown_conversation_returns_consistent_404(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]))->getJson('/api/admin/whatsapp/conversations/999')
            ->assertNotFound()->assertJsonPath('success', false);
    }

    public function test_administrator_command_grants_and_revokes_access(): void
    {
        $user = User::factory()->create();
        $this->artisan('whatsapp:admin', ['email' => $user->email])->assertSuccessful();
        $this->assertTrue($user->refresh()->is_admin);
        $this->artisan('whatsapp:admin', ['email' => $user->email, '--revoke' => true])->assertSuccessful();
        $this->assertFalse($user->refresh()->is_admin);
    }

    public function test_administrator_command_provisions_account_using_interactive_password(): void
    {
        $this->artisan('whatsapp:admin', ['email' => 'admin@example.com', '--create' => true])
            ->expectsQuestion('Name', 'Administrador')
            ->expectsQuestion('Password', 'SecurePassword123')
            ->expectsQuestion('Confirm password', 'SecurePassword123')
            ->assertSuccessful();
        $user = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $this->assertTrue($user->is_admin);
        $this->assertTrue(Hash::check('SecurePassword123', $user->password));
    }

    public function test_takeover_discards_pending_bot_reply_before_return_to_bot(): void
    {
        $conversation = WhatsAppConversation::factory()->create(['state' => 'ASK_ORIGIN']);
        $inbound = WhatsAppMessage::factory()->for($conversation, 'conversation')->create([
            'processing_context' => ['input_applied' => true, 'pending_response' => 'Respuesta pendiente'],
        ]);
        $this->actingAs(User::factory()->create(['is_admin' => true]))->postJson('/api/admin/whatsapp/conversations/'.$conversation->id.'/takeover')->assertOk();

        $this->assertNotNull($inbound->refresh()->processed_at);
        $this->assertNull($inbound->processing_context);
    }

    public function test_cors_allows_only_configured_frontend(): void
    {
        config(['cors.allowed_origins' => ['https://admin.example.com']]);
        $this->options('/api/admin/login', [], ['Origin' => 'https://admin.example.com', 'Access-Control-Request-Method' => 'POST'])
            ->assertHeader('Access-Control-Allow-Origin', 'https://admin.example.com')->assertHeader('Access-Control-Allow-Credentials', 'true');
        $this->options('/api/admin/login', [], ['Origin' => 'https://attacker.example.com', 'Access-Control-Request-Method' => 'POST'])
            ->assertHeader('Access-Control-Allow-Origin', 'https://admin.example.com');
    }
}
