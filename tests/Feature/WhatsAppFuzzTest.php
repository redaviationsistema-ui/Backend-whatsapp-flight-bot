<?php

namespace Tests\Feature;

use App\Models\WhatsAppConversation;
use App\Models\WhatsAppFlightRequest;
use App\Services\WhatsApp\WhatsAppChatbotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class WhatsAppFuzzTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['DE TOLUCA A CANCUN'])]
    #[TestWith(['de toluca a cancun!!!'])]
    #[TestWith(['mañana ✈️'])]
    #[TestWith(['somos 4 👥'])]
    #[TestWith(['8 pm'])]
    #[TestWith(['quiero salir de toluca el viernes somos 4 vamos a cancun como a las 8'])]
    #[TestWith(['<script>alert("x")</script>'])]
    #[TestWith(['{"origin":"Toluca","destination":"Cancun"}'])]
    #[TestWith(['https://example.com/cotizar?from=toluca'])]
    #[TestWith(['     '])]
    #[TestWith(['🙂🙂🙂'])]
    public function test_fuzzy_inputs_never_throw_or_persist_invalid_location_noise(string $input): void
    {
        $this->travelTo(Carbon::parse('2026-09-22', config('whatsapp.timezone')));
        $flight = WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()->state(['state' => 'ASK_ORIGIN']), 'conversation')
            ->create();

        $result = $this->answer($flight, $input);

        $this->assertIsString($result['state']);
        $this->assertIsString($result['message']);
        $this->assertNotSame('<script>alert("x")</script>', $flight->refresh()->origin);
        $this->assertNotSame('{"origin":"Toluca","destination":"Cancun"}', $flight->origin);
        $this->assertNotSame('https://example.com/cotizar?from=toluca', $flight->origin);
    }

    public function test_overlong_input_does_not_advance_or_overwrite_current_field(): void
    {
        $flight = WhatsAppFlightRequest::factory()
            ->for(WhatsAppConversation::factory()->state(['state' => 'ASK_EMAIL']), 'conversation')
            ->create(['origin' => 'Toluca', 'destination' => 'Cancún']);

        $result = $this->answer($flight, str_repeat('x', 5001));

        $this->assertSame('ASK_EMAIL', $result['state']);
        $this->assertNull($flight->refresh()->client_email);
        $this->assertSame('Toluca', $flight->origin);
        $this->assertSame('Cancún', $flight->destination);
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
