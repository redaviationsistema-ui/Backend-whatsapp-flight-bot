<?php

namespace App\Http\Controllers\WhatsApp;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWhatsAppMessage;
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

    public function receive(Request $request): Response
    {
        if (($request->input('object') ?? null) !== 'whatsapp_business_account') {
            return response('EVENT_RECEIVED', 200);
        }

        foreach ($this->incomingMessages($request->all()) as $messagePayload) {
            ProcessWhatsAppMessage::dispatch($messagePayload);
        }

        return response('EVENT_RECEIVED', 200);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function incomingMessages(array $payload): array
    {
        $messages = [];

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                $contacts = collect($value['contacts'] ?? [])->keyBy('wa_id');

                foreach ($value['messages'] ?? [] as $message) {
                    $from = $message['from'] ?? null;

                    if (! $from || ! isset($message['id'])) {
                        continue;
                    }

                    $messages[] = [
                        'message' => $message,
                        'contact' => $contacts->get($from, []),
                        'metadata' => $value['metadata'] ?? [],
                    ];
                }
            }
        }

        return $messages;
    }
}
