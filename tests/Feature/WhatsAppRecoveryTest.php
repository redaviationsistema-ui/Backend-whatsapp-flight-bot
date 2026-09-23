<?php

namespace Tests\Feature;

use App\Models\WhatsAppConversation;
use App\Models\WhatsAppFlightRequest;
use App\Services\WhatsApp\WhatsAppChatbotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class WhatsAppRecoveryTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['ASK_CATERING'])]
    #[TestWith(['ASK_WIFI'])]
    #[TestWith(['ASK_LUGGAGE'])]
    #[TestWith(['ASK_PETS'])]
    #[TestWith(['ASK_GROUND_TRANSPORT'])]
    #[TestWith(['STATE_THAT_NO_LONGER_EXISTS'])]
    public function test_legacy_or_unknown_states_recover_to_next_safe_missing_field(string $legacyState): void
    {
        $flight = WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()->state(['state' => $legacyState]), 'conversation')
            ->create([
                'origin' => 'Toluca',
                'destination' => 'Cancún',
            ]);

        $result = $this->answer($flight, 'continuar');

        $this->assertSame('ASK_DEPARTURE_DATE', $result['state']);
        $this->assertSame('Toluca', $flight->refresh()->origin);
        $this->assertSame('Cancún', $flight->destination);
        $this->assertSame($legacyState, $flight->conversation->refresh()->metadata['last_state_recovery']['from']);
    }

    public function test_new_quote_clears_active_request_data_without_inheriting_previous_fields(): void
    {
        $flight = WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()->state(['state' => 'SHOW_SUMMARY']), 'conversation')
            ->create([
                'origin' => 'Toluca',
                'destination' => 'Cancún',
                'departure_date' => '2026-10-02',
                'departure_time' => '20:00:00',
                'passengers' => 4,
                'trip_type' => 'ONE_WAY',
                'client_name' => 'Kevin Lael',
                'client_email' => 'kevin@example.com',
                'notes' => 'prefiere Toluca',
                'budget' => 20000,
            ]);

        $result = $this->answer($flight, 'Quiero hacer otro vuelo');
        $flight->refresh();

        $this->assertSame('ASK_ORIGIN', $result['state']);
        foreach (['origin', 'destination', 'departure_date', 'departure_time', 'passengers', 'trip_type', 'client_name', 'client_email', 'notes', 'budget'] as $field) {
            $this->assertNull($flight->{$field}, "Field {$field} should not be inherited.");
        }
    }

    public function test_engine_observability_log_is_structured_and_does_not_include_message_body_or_secrets(): void
    {
        Log::spy();
        $flight = WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()->state(['state' => 'ASK_ORIGIN']), 'conversation')
            ->create();

        $this->answer($flight, 'La salida sería de Toluca a Cancún');

        Log::shouldHaveReceived('info')->with('WhatsApp chatbot engine decision.', \Mockery::on(
            fn (array $context): bool => $context['engine_version'] === 'whatsapp_autopilot_v1'
                && $context['state_before'] === 'ASK_ORIGIN'
                && $context['intent'] === 'FLIGHT_QUOTE'
                && in_array('origin', $context['extracted_fields'], true)
                && ! array_key_exists('message', $context)
                && ! array_key_exists('access_token', $context)
                && ! array_key_exists('app_secret', $context)
        ))->once();
    }

    /** @return array{state:string,message:string} */
    private function answer(WhatsAppFlightRequest $flight, string $answer): array
    {
        $conversation = $flight->conversation()->firstOrFail();
        $result = app(WhatsAppChatbotService::class)->handleIncomingMessage($conversation, $flight->refresh(), $answer);
        $conversation->update(['state' => $result['state']]);

        return $result;
    }
}
