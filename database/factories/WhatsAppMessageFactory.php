<?php

namespace Database\Factories;

use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WhatsAppMessage>
 */
class WhatsAppMessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'whats_app_conversation_id' => WhatsAppConversation::factory(),
            'message_id' => 'wamid.'.fake()->unique()->uuid(),
            'direction' => 'inbound',
            'type' => 'text',
            'body' => 'Hola',
            'sent_at' => now(),
        ];
    }
}
