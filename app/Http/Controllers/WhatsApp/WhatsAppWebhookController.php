<?php

namespace App\Http\Controllers\WhatsApp;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWhatsAppMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        $mode = $request->query('hub_mode') ?? $request->query('hub.mode');
        $token = $request->query('hub_verify_token') ?? $request->query('hub.verify_token');
        $challenge = $request->query('hub_challenge') ?? $request->query('hub.challenge');
        $verifyToken = (string) (config('services.whatsapp.verify_token') ?? config('whatsapp.verify_token'));

        if ($verifyToken !== '' && $mode === 'subscribe' && hash_equals($verifyToken, (string) $token)) {
            return response((string) $challenge, 200)
                ->header('Content-Type', 'text/plain');
        }

        Log::warning('WhatsApp webhook verification failed.', [
            'mode' => $mode,
        ]);

        return response('Forbidden', 403);
    }

    public function receive(Request $request): JsonResponse|Response
    {
        Log::info('WhatsApp webhook POST received.', [
            'object' => $request->input('object'),
            'has_entries' => ! empty($request->input('entry', [])),
            'queue_connection' => config('queue.default'),
            'configured_phone_number_id' => config('services.whatsapp.phone_number_id'),
            'incoming_waba_id' => $request->input('entry.0.id'),
            'incoming_phone_number_id' => $request->input('entry.0.changes.0.value.metadata.phone_number_id'),
        ]);

        if (! $this->hasValidMetaSignature($request)) {
            Log::warning('WhatsApp webhook rejected because Meta signature is invalid.');

            return response('Forbidden', 403);
        }

        $payload = $request->all();
        $messageCount = $this->incomingMessageCount($payload);

        Log::info('WhatsApp webhook payload inspected.', [
            'object' => $payload['object'] ?? null,
            'message_count' => $messageCount,
            'first_from' => data_get($payload, 'entry.0.changes.0.value.messages.0.from'),
            'first_message_id' => data_get($payload, 'entry.0.changes.0.value.messages.0.id'),
            'first_type' => data_get($payload, 'entry.0.changes.0.value.messages.0.type'),
            'first_text_body_present' => data_get($payload, 'entry.0.changes.0.value.messages.0.text.body') !== null,
            'incoming_waba_id' => data_get($payload, 'entry.0.id'),
            'incoming_phone_number_id' => data_get($payload, 'entry.0.changes.0.value.metadata.phone_number_id'),
            'configured_phone_number_id' => config('services.whatsapp.phone_number_id'),
        ]);

        if (($payload['object'] ?? null) !== 'whatsapp_business_account') {
            return response()->json(['received' => true]);
        }

        ProcessWhatsAppMessage::dispatch($payload);

        Log::info('WhatsApp webhook message payload dispatched.', [
            'message_count' => $messageCount,
            'queue_connection' => config('queue.default'),
        ]);

        return response()->json(['received' => true]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hasIncomingMessages(array $payload): bool
    {
        return $this->incomingMessageCount($payload) > 0;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function incomingMessageCount(array $payload): int
    {
        $count = 0;

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $count += count($change['value']['messages'] ?? []);
            }
        }

        return $count;
    }

    private function hasValidMetaSignature(Request $request): bool
    {
        $appSecret = config('services.whatsapp.app_secret');

        if (! $appSecret) {
            return false;
        }

        $signature = (string) $request->header('X-Hub-Signature-256');

        if (! str_starts_with($signature, 'sha256=')) {
            Log::warning('WhatsApp webhook signature header is missing or malformed.', [
                'has_signature' => $signature !== '',
            ]);

            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), (string) $appSecret);

        return hash_equals($expected, $signature);
    }
}
