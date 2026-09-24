<?php

namespace App\Http\Controllers\WhatsApp;

use App\Http\Controllers\Controller;
use App\Http\Resources\WhatsAppAdminRequestResource;
use App\Models\AdvisorRequest;
use App\Models\EngineRequest;
use App\Models\PartRequest;
use App\Models\SupportRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;

class WhatsAppAdminRequestController extends Controller
{
    private const Statuses = ['NUEVA', 'PENDIENTE', 'EN_ATENCION', 'CERRADA', 'CANCELADA'];

    public function parts(Request $request): AnonymousResourceCollection
    {
        return $this->index($request, PartRequest::query(), ['part_number', 'description', 'comments'], ['status', 'condition']);
    }

    public function engines(Request $request): AnonymousResourceCollection
    {
        return $this->index($request, EngineRequest::query(), ['engine_model', 'part_number', 'serial_number', 'comments'], ['status', 'condition', 'service_type']);
    }

    public function support(Request $request): AnonymousResourceCollection
    {
        return $this->index($request, SupportRequest::query(), ['reason', 'reference', 'description', 'comments'], ['status', 'reason', 'priority']);
    }

    public function advisor(Request $request): AnonymousResourceCollection
    {
        $query = AdvisorRequest::query();

        if ($request->boolean('transferred')) {
            $query->whereNotNull('transferred_at');
        }

        return $this->index($request, $query, ['reason', 'reference', 'comments'], ['status', 'reason']);
    }

    public function showPart(PartRequest $partRequest): WhatsAppAdminRequestResource
    {
        return $this->show($partRequest);
    }

    public function showEngine(EngineRequest $engineRequest): WhatsAppAdminRequestResource
    {
        return $this->show($engineRequest);
    }

    public function showSupport(SupportRequest $supportRequest): WhatsAppAdminRequestResource
    {
        return $this->show($supportRequest);
    }

    public function showAdvisor(AdvisorRequest $advisorRequest): WhatsAppAdminRequestResource
    {
        return $this->show($advisorRequest);
    }

    public function updatePartStatus(Request $request, PartRequest $partRequest): WhatsAppAdminRequestResource
    {
        return $this->updateStatus($request, $partRequest);
    }

    public function updateEngineStatus(Request $request, EngineRequest $engineRequest): WhatsAppAdminRequestResource
    {
        return $this->updateStatus($request, $engineRequest);
    }

    public function updateSupportStatus(Request $request, SupportRequest $supportRequest): WhatsAppAdminRequestResource
    {
        return $this->updateStatus($request, $supportRequest);
    }

    public function updateAdvisorStatus(Request $request, AdvisorRequest $advisorRequest): WhatsAppAdminRequestResource
    {
        return $this->updateStatus($request, $advisorRequest);
    }

    /**
     * @param  Builder<Model>  $query
     * @param  array<int, string>  $searchColumns
     * @param  array<int, string>  $filterColumns
     */
    private function index(Request $request, Builder $query, array $searchColumns, array $filterColumns): AnonymousResourceCollection
    {
        $data = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable', 'string', 'max:50'],
            'condition' => ['sometimes', 'nullable', 'string', 'max:50'],
            'service_type' => ['sometimes', 'nullable', 'string', 'max:50'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:50'],
            'priority' => ['sometimes', 'nullable', 'string', 'max:50'],
            'date_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);

        $query->with(['contact', 'conversation'])
            ->when($data['search'] ?? null, function (Builder $query, string $search) use ($searchColumns): void {
                $query->where(function (Builder $query) use ($searchColumns, $search): void {
                    foreach ($searchColumns as $column) {
                        $query->orWhere($column, 'like', "%{$search}%");
                    }
                    $query->orWhereHas('contact', fn (Builder $query): Builder => $query
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('phone_number', 'like', "%{$search}%"));
                });
            })
            ->when($data['date_from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date))
            ->when($data['date_to'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '<=', $date));

        foreach ($filterColumns as $column) {
            $query->when($data[$column] ?? null, fn (Builder $query, string $value): Builder => $query->where($column, $value));
        }

        return WhatsAppAdminRequestResource::collection($query->latest()->paginate($data['per_page'] ?? 25)->withQueryString())
            ->additional(['success' => true]);
    }

    private function show(Model $requestModel): WhatsAppAdminRequestResource
    {
        return (new WhatsAppAdminRequestResource($requestModel->load(['contact', 'conversation'])))
            ->additional(['success' => true]);
    }

    private function updateStatus(Request $request, Model $requestModel): WhatsAppAdminRequestResource
    {
        $data = $request->validate(['status' => ['required', 'string', 'in:'.implode(',', self::Statuses)]]);
        $oldStatus = $requestModel->getAttribute('status');
        $requestModel->update(['status' => $data['status']]);

        Log::info('admin_status_changed', [
            'model' => $requestModel::class,
            'id' => $requestModel->getKey(),
            'old_status' => $oldStatus,
            'new_status' => $data['status'],
            'admin_user_id' => $request->user()?->getAuthIdentifier(),
        ]);

        return (new WhatsAppAdminRequestResource($requestModel->refresh()->load(['contact', 'conversation'])))
            ->additional(['success' => true]);
    }
}
