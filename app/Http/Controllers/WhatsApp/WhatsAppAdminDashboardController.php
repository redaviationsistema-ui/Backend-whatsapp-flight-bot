<?php

namespace App\Http\Controllers\WhatsApp;

use App\Http\Controllers\Controller;
use App\Models\AdvisorRequest;
use App\Models\EngineRequest;
use App\Models\PartRequest;
use App\Models\SupportRequest;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppFlightRequest;
use App\Models\WhatsAppMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WhatsAppAdminDashboardController extends Controller
{
    public function dashboard(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => [
            'conversations' => [
                'total' => WhatsAppConversation::query()->count(),
                'active' => WhatsAppConversation::query()->where('is_active', true)->count(),
                'human' => WhatsAppConversation::query()->whereNotNull('transferred_to_human_at')->orWhere('state', 'TRANSFER_TO_HUMAN')->count(),
            ],
            'flight_quotes' => ['total' => WhatsAppFlightRequest::query()->count()],
            'parts' => $this->requestMetrics(PartRequest::class),
            'engines' => $this->requestMetrics(EngineRequest::class),
            'support' => $this->requestMetrics(SupportRequest::class),
            'advisor' => $this->requestMetrics(AdvisorRequest::class),
        ]]);
    }

    public function history(Request $request): JsonResponse
    {
        $data = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);

        $messages = WhatsAppMessage::query()
            ->with('conversation.contact')
            ->latest('created_at')
            ->paginate($data['per_page'] ?? 25)
            ->withQueryString();

        return response()->json([
            'success' => true,
            'data' => $messages->through(fn (WhatsAppMessage $message): array => [
                'id' => 'message-'.$message->id,
                'type' => 'message',
                'conversation_id' => $message->whats_app_conversation_id,
                'contact' => $message->conversation?->contact?->only(['id', 'name', 'phone_number']),
                'direction' => $message->direction,
                'body' => $message->body,
                'status' => $message->status,
                'created_at' => $message->created_at,
            ])->items(),
            'links' => [
                'first' => $messages->url(1),
                'last' => $messages->url($messages->lastPage()),
                'prev' => $messages->previousPageUrl(),
                'next' => $messages->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $messages->currentPage(),
                'per_page' => $messages->perPage(),
                'total' => $messages->total(),
            ],
        ]);
    }

    /** @param class-string $model */
    private function requestMetrics(string $model): array
    {
        return [
            'total' => $model::query()->count(),
            'new' => $model::query()->where('status', 'NUEVA')->count(),
            'in_progress' => $model::query()->where('status', 'EN_ATENCION')->count(),
        ];
    }
}
