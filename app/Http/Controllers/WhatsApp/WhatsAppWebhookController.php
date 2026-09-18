<?php

namespace App\Http\Controllers\WhatsApp;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWhatsAppMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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
        if (! $this->hasValidMetaSignature($request)) {
            return response('Forbidden', 403);
        }

        $payload = $request->all();

        if (($payload['object'] ?? null) !== 'whatsapp_business_account' || ! $this->hasIncomingMessages($payload)) {
            return response()->json(['received' => true]);
        }

        ProcessWhatsAppMessage::dispatch($payload);

        return response()->json(['received' => true]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hasIncomingMessages(array $payload): bool
    {
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                if (! empty($change['value']['messages'] ?? [])) {
                    return true;
                }
            }
        }

        return false;
    }

    private function hasValidMetaSignature(Request $request): bool
    {
        $appSecret = config('services.whatsapp.app_secret');

        if (! $appSecret) {
            return true;
        }

        $signature = (string) $request->header('X-Hub-Signature-256');

        if (! str_starts_with($signature, 'sha256=')) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), (string) $appSecret);

        return hash_equals($expected, $signature);
    }
}
