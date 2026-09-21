<?php

namespace Database\Factories;

use App\Models\WhatsAppContact;
use App\Models\WhatsAppConversation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WhatsAppConversation>
 */
class WhatsAppConversationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'whats_app_contact_id' => WhatsAppContact::factory(),
            'state' => 'START',
            'is_active' => true,
            'last_message_at' => now(),
        ];
    }
}
