<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class WhatsAppService
{
    /**
     * @return array<string, mixed>
     */
    public function sendTextMessage(string $to, string $message): array
    {
        return $this->send([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'text',
            'text' => [
                'preview_url' => false,
                'body' => $message,
            ],
        ]);
    }

    /**
     * @param  array<int, array{id:string,title:string}>  $buttons
     * @return array<string, mixed>
     */
    public function sendButtonMessage(string $to, string $body, array $buttons): array
    {
        $formattedButtons = collect($buttons)
            ->map(fn (array $button): array => [
                'type' => 'reply',
                'reply' => [
                    'id' => $button['id'],
                    'title' => $button['title'],
                ],
            ])
            ->values()
            ->all();

        return $this->sendInteractiveMessage($to, [
            'type' => 'button',
            'body' => [
                'text' => $body,
            ],
            'action' => [
                'buttons' => $formattedButtons,
            ],
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $sections
     * @return array<string, mixed>
     */
    public function sendListMessage(string $to, string $body, string $buttonText, array $sections): array
    {
        return $this->sendInteractiveMessage($to, [
            'type' => 'list',
            'body' => [
                'text' => $body,
            ],
            'action' => [
                'button' => $buttonText,
                'sections' => $sections,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $interactive
     * @return array<string, mixed>
     */
    public function sendInteractiveMessage(string $to, array $interactive): array
    {
        return $this->send([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'interactive',
            'interactive' => $interactive,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function send(array $payload): array
    {
        $phoneNumberId = config('whatsapp.phone_number_id');
        $accessToken = config('whatsapp.access_token');
        $apiVersion = config('whatsapp.api_version', 'v20.0');

        if (! $phoneNumberId || ! $accessToken) {
            Log::warning('WhatsApp message not sent because credentials are missing.', [
                'to' => $payload['to'] ?? null,
            ]);

            return [];
        }

        try {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(15)
                ->post("https://graph.facebook.com/{$apiVersion}/{$phoneNumberId}/messages", $payload);

            if ($response->failed()) {
                Log::error('WhatsApp Meta API error.', [
                    'status' => $response->status(),
                    'response' => $response->json(),
                ]);

                throw new RuntimeException('Meta WhatsApp API request failed.');
            }

            return $response->json() ?? [];
        } catch (RequestException $exception) {
            Log::error('WhatsApp HTTP request failed.', [
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
