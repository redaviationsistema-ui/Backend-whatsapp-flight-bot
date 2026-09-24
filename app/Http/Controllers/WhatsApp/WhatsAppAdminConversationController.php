<?php

namespace App\Http\Controllers\WhatsApp;

use App\Http\Controllers\Controller;
use App\Http\Resources\WhatsAppAdminRequestResource;
use App\Http\Resources\WhatsAppConversationResource;
use App\Http\Resources\WhatsAppMessageResource;
use App\Models\AdvisorRequest;
use App\Models\EngineRequest;
use App\Models\PartRequest;
use App\Models\SupportRequest;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\WhatsAppChatbotService;
use App\Services\WhatsApp\WhatsAppConversationService;
use App\Services\WhatsApp\WhatsAppMessageService;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Database\Eloquent\Builder;
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
        $data = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'state' => ['sometimes', 'nullable', 'string', 'max:80'],
            'active_section' => ['sometimes', 'nullable', 'string', 'max:40'],
            'transferred_to_human' => ['sometimes', 'boolean'],
            'date_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);

        return WhatsAppConversationResource::collection(WhatsAppConversation::query()
            ->with(['contact', 'latestMessage'])
            ->when($data['search'] ?? null, fn (Builder $query, string $search): Builder => $query->whereHas('contact', fn (Builder $query): Builder => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('phone_number', 'like', "%{$search}%")))
            ->when($data['state'] ?? null, fn (Builder $query, string $state): Builder => $query->where('state', $state))
            ->when($data['active_section'] ?? null, fn (Builder $query, string $section): Builder => $query->where('metadata->active_section', $section))
            ->when(array_key_exists('transferred_to_human', $data), fn (Builder $query): Builder => $request->boolean('transferred_to_human')
                ? $query->whereNotNull('transferred_to_human_at')
                : $query->whereNull('transferred_to_human_at'))
            ->when($data['date_from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date))
            ->when($data['date_to'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '<=', $date))
            ->orderByRaw('CASE WHEN last_message_at IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('last_message_at')->orderByDesc('id')
            ->paginate($data['per_page'] ?? 25)->withQueryString())->additional(['success' => true]);
    }

    public function show(WhatsAppConversation $conversation, WhatsAppChatbotService $chatbot): JsonResponse
    {
        $conversation->load(['contact', 'latestMessage', 'flightRequest', 'messages' => fn ($query) => $query->orderBy('created_at')->orderBy('id')]);

        return response()->json([
            'success' => true,
            'data' => (new WhatsAppConversationResource($conversation))->resolve(),
            'summary' => $conversation->flightRequest ? $chatbot->summaryMessage($conversation->flightRequest) : null,
            'messages' => WhatsAppMessageResource::collection($conversation->messages)->resolve(),
            'related_requests' => [
                'parts' => WhatsAppAdminRequestResource::collection(PartRequest::query()->with('contact')->where('whats_app_conversation_id', $conversation->id)->latest()->get())->resolve(),
                'engines' => WhatsAppAdminRequestResource::collection(EngineRequest::query()->with('contact')->where('whats_app_conversation_id', $conversation->id)->latest()->get())->resolve(),
                'support' => WhatsAppAdminRequestResource::collection(SupportRequest::query()->with('contact')->where('whats_app_conversation_id', $conversation->id)->latest()->get())->resolve(),
                'advisor' => WhatsAppAdminRequestResource::collection(AdvisorRequest::query()->with('contact')->where('whats_app_conversation_id', $conversation->id)->latest()->get())->resolve(),
            ],
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
        Log::info('transfer_to_human', [
            'conversation_id' => $conversation->id,
            'admin_user_id' => request()->user()?->getAuthIdentifier(),
        ]);

        return (new WhatsAppConversationResource($conversation->refresh()->load('contact')))->additional(['success' => true]);
    }

    public function returnToBot(WhatsAppConversation $conversation, WhatsAppConversationService $conversations): WhatsAppConversationResource
    {
        $conversations->withContactLock($conversation->contact->phone_number, fn () => $conversations->returnToBot($conversation->refresh()));
        Log::info('return_to_bot', [
            'conversation_id' => $conversation->id,
            'admin_user_id' => request()->user()?->getAuthIdentifier(),
        ]);

        return (new WhatsAppConversationResource($conversation->refresh()->load('contact')))->additional(['success' => true]);
    }
}
