<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

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
        $phoneNumberId = config('services.whatsapp.phone_number_id') ?? config('whatsapp.phone_number_id');
        $accessToken = config('services.whatsapp.access_token') ?? config('whatsapp.access_token');
        $apiVersion = config('services.whatsapp.api_version') ?? config('whatsapp.api_version', 'v26.0');

        if (! $phoneNumberId || ! $accessToken) {
            Log::warning('WhatsApp message not sent because credentials are missing.', [
                'to' => $payload['to'] ?? null,
                'has_phone_number_id' => (bool) $phoneNumberId,
                'has_access_token' => (bool) $accessToken,
            ]);

            throw new WhatsAppSendRejectedException('WhatsApp credentials are not configured.');
        }

        try {
            $url = "https://graph.facebook.com/{$apiVersion}/{$phoneNumberId}/messages";

            Log::info('Sending WhatsApp message through Meta API.', [
                'event' => 'whatsapp_reply_sent_attempt',
                'to_last4' => substr((string) ($payload['to'] ?? ''), -4),
                'type' => $payload['type'] ?? null,
                'body_length' => strlen((string) data_get($payload, 'text.body', '')),
                'api_version' => $apiVersion,
                'configured_phone_number_id' => $phoneNumberId,
            ]);

            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(15)
                ->post($url, $payload);

            Log::info('whatsapp_reply_sent', [
                'status' => $response->status(),
                'message_id' => $response->json('messages.0.id'),
            ]);

            if (! $response->successful() || $response->json('error') !== null || ! is_string($response->json('messages.0.id')) || $response->json('messages.0.id') === '') {
                Log::error('whatsapp_reply_failed', [
                    'status' => $response->status(),
                    'error_code' => $response->json('error.code'),
                    'error_type' => $response->json('error.type'),
                ]);

                if ($response->json('error') !== null || $response->clientError()) {
                    throw new WhatsAppSendRejectedException('Meta WhatsApp API request failed.');
                }

                throw new RuntimeException('Meta WhatsApp send outcome is unknown.');
            }

            return $response->json() ?? [];
        } catch (RequestException $exception) {
            Log::error('WhatsApp HTTP request failed.', [
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        } catch (Throwable $throwable) {
            Log::error('WhatsApp message send failed.', [
                'error' => $throwable->getMessage(),
                'to' => $payload['to'] ?? null,
            ]);

            throw $throwable;
        }
    }
}
