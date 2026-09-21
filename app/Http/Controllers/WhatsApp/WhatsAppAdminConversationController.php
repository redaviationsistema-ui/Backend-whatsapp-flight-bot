<?php

namespace App\Http\Controllers\WhatsApp;

use App\Http\Controllers\Controller;
use App\Http\Resources\WhatsAppConversationResource;
use App\Http\Resources\WhatsAppMessageResource;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\WhatsAppChatbotService;
use App\Services\WhatsApp\WhatsAppConversationService;
use App\Services\WhatsApp\WhatsAppMessageService;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class WhatsAppAdminConversationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);

        return WhatsAppConversationResource::collection(WhatsAppConversation::query()
            ->with(['contact', 'latestMessage'])
            ->orderByRaw('CASE WHEN last_message_at IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('last_message_at')->orderByDesc('id')
            ->paginate($data['per_page'] ?? 25)->withQueryString())->additional(['success' => true]);
    }

    public function show(WhatsAppConversation $conversation, WhatsAppChatbotService $chatbot): WhatsAppConversationResource
    {
        $conversation->load(['contact', 'latestMessage', 'flightRequest']);

        return (new WhatsAppConversationResource($conversation))->additional([
            'success' => true,
            'summary' => $conversation->flightRequest ? $chatbot->summaryMessage($conversation->flightRequest) : null,
        ]);
    }

    public function messages(Request $request, WhatsAppConversation $conversation): AnonymousResourceCollection
    {
        $data = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);

        return WhatsAppMessageResource::collection($conversation->messages()->orderBy('sent_at')->orderBy('id')->paginate($data['per_page'] ?? 50)->withQueryString())
            ->additional(['success' => true]);
    }

    public function send(Request $request, WhatsAppConversation $conversation, WhatsAppConversationService $conversations, WhatsAppMessageService $messages, WhatsAppService $whatsapp): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:4096']]);
        try {
            $message = $conversations->withContactLock($conversation->contact->phone_number, function () use ($conversation, $data, $messages, $whatsapp): WhatsAppMessage {
                $response = $whatsapp->sendTextMessage($conversation->contact->phone_number, $data['body']);

                return DB::transaction(fn () => $messages->storeOutboundMessage($conversation, $data['body'], $response));
            });
        } catch (Throwable $exception) {
            Log::error('Administrative WhatsApp send failed.', ['conversation_id' => $conversation->id, 'exception_type' => $exception::class]);

            return response()->json(['success' => false, 'message' => 'No fue posible enviar el mensaje'], 502);
        }

        return response()->json(['success' => true, 'data' => new WhatsAppMessageResource($message)], 201);
    }

    public function takeover(WhatsAppConversation $conversation, WhatsAppConversationService $conversations): WhatsAppConversationResource
    {
        $conversations->withContactLock($conversation->contact->phone_number, function () use ($conversation, $conversations): void {
            DB::transaction(function () use ($conversation, $conversations): void {
                $conversations->transferToHuman($conversation->refresh());
                $conversation->messages()->where('direction', 'inbound')->whereNull('processed_at')->update(['processed_at' => now(), 'processing_context' => null]);
            });
        });

        return (new WhatsAppConversationResource($conversation->refresh()->load('contact')))->additional(['success' => true]);
    }

    public function returnToBot(WhatsAppConversation $conversation, WhatsAppConversationService $conversations): WhatsAppConversationResource
    {
        $conversations->withContactLock($conversation->contact->phone_number, fn () => $conversations->returnToBot($conversation->refresh()));

        return (new WhatsAppConversationResource($conversation->refresh()->load('contact')))->additional(['success' => true]);
    }
}
