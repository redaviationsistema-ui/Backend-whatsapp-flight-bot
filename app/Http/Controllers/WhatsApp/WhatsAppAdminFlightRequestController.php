<?php

namespace App\Http\Controllers\WhatsApp;

use App\Http\Controllers\Controller;
use App\Http\Resources\WhatsAppAdminFlightRequestResource;
use App\Models\WhatsAppFlightRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WhatsAppAdminFlightRequestController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'origin' => ['sometimes', 'nullable', 'string', 'max:100'],
            'destination' => ['sometimes', 'nullable', 'string', 'max:100'],
            'aircraft' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable', 'string', 'max:50'],
            'date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'date_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);

        $query = WhatsAppFlightRequest::query()
            ->with(['conversation.contact'])
            ->when($data['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('client_name', 'like', "%{$search}%")
                        ->orWhere('client_email', 'like', "%{$search}%")
                        ->orWhereHas('conversation.contact', function (Builder $query) use ($search): void {
                            $query->where('name', 'like', "%{$search}%")
                                ->orWhere('phone_number', 'like', "%{$search}%");
                        });
                });
            })
            ->when($data['origin'] ?? null, fn (Builder $query, string $origin): Builder => $query->where('origin', 'like', "%{$origin}%"))
            ->when($data['destination'] ?? null, fn (Builder $query, string $destination): Builder => $query->where('destination', 'like', "%{$destination}%"))
            ->when($data['aircraft'] ?? null, fn (Builder $query, string $aircraft): Builder => $query->where(function (Builder $query) use ($aircraft): void {
                $query->where('selected_aircraft', 'like', "%{$aircraft}%")
                    ->orWhere('selected_aircraft_id', 'like', "%{$aircraft}%");
            }))
            ->when($data['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->when($data['date'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('departure_date', $date))
            ->when($data['date_from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date))
            ->when($data['date_to'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '<=', $date))
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        return WhatsAppAdminFlightRequestResource::collection($query->paginate($data['per_page'] ?? 25)->withQueryString())
            ->additional(['success' => true]);
    }

    public function show(WhatsAppFlightRequest $flightRequest): WhatsAppAdminFlightRequestResource
    {
        return (new WhatsAppAdminFlightRequestResource($flightRequest->load(['conversation.contact'])))
            ->additional(['success' => true]);
    }
}
