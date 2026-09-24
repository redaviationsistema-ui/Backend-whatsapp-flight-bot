<?php

namespace Tests\Feature;

use App\Models\WhatsAppConversation;
use App\Models\WhatsAppFlightRequest;
use App\Services\WhatsApp\WhatsAppChatbotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WhatsAppStressTest extends TestCase
{
    use RefreshDatabase;

    public function test_long_indecisive_conversation_preserves_context_and_reaches_summary(): void
    {
        $this->travelTo(Carbon::parse('2026-09-22', config('whatsapp.timezone')));
        $flight = WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()->state(['state' => 'START']), 'conversation')
            ->create();

        $messages = [
            'Hola, necesito un vuelo pero todavía no tengo todo definido.',
            'Saldríamos de Toluca.',
            'A Cancún.',
            'Sería este viernes.',
            'Como a las 7 u 8 de la noche.',
            '8 de la noche.',
            'solo ida',
            'Somos 6 adultos y 2 niños.',
            'No, espera, somos 7 en total.',
            'No tengo preferencia de avión.',
            '¿Qué sigue?',
            'Sí',
            'Sí, alternos están bien, pero prefiero Toluca.',
            'ninguno',
            'Kevin',
            'Kevin Lael De Jesús Flores.',
            'Mi correo es kevin@example.com',
            'Sin notas',
        ];

        foreach ($messages as $message) {
            $result = $this->answer($flight, $message);
            if ($flight->refresh()->origin) {
                $this->assertNotSame('ASK_ORIGIN', $result['state'], "Unexpected origin reset after: {$message}");
            }
        }

        $flight->refresh();
        $summary = app(WhatsAppChatbotService::class)->summaryMessage($flight);

        $this->assertSame('Toluca', $flight->origin);
        $this->assertSame('Cancún', $flight->destination);
        $this->assertSame('2026-09-25', $flight->departure_date->toDateString());
        $this->assertSame('20:00:00', $flight->departure_time);
        $this->assertSame(8, $flight->passengers);
        $this->assertSame('ONE_WAY', $flight->trip_type);
        $this->assertNull($flight->return_date);
        $this->assertNull($flight->return_time);
        $this->assertSame('SHOW_SUMMARY', $flight->conversation->refresh()->state);
        $this->assertStringContainsString('Toluca', $summary);
        $this->assertStringContainsString('8 pasajeros', $summary);
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
