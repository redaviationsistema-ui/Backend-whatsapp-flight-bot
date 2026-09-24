<?php

namespace App\Http\Resources;

use App\Models\AdvisorRequest;
use App\Models\EngineRequest;
use App\Models\PartRequest;
use App\Models\SupportRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WhatsAppAdminRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $base = [
            'id' => $this->id,
            'conversation_id' => $this->whats_app_conversation_id,
            'contact' => $this->whenLoaded('contact', fn (): array => $this->contact->only(['id', 'name', 'phone_number'])),
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];

        return match (true) {
            $this->resource instanceof PartRequest => [
                ...$base,
                'part_number' => $this->part_number,
                'description' => $this->description,
                'quantity' => $this->quantity,
                'condition' => $this->condition,
                'comments' => $this->comments,
            ],
            $this->resource instanceof EngineRequest => [
                ...$base,
                'engine_model' => $this->engine_model,
                'part_number' => $this->part_number,
                'serial_number' => $this->serial_number,
                'condition' => $this->condition,
                'service_type' => $this->service_type,
                'comments' => $this->comments,
            ],
            $this->resource instanceof SupportRequest => [
                ...$base,
                'reason' => $this->reason,
                'reference' => $this->reference,
                'description' => $this->description,
                'priority' => $this->priority,
                'comments' => $this->comments,
            ],
            $this->resource instanceof AdvisorRequest => [
                ...$base,
                'reason' => $this->reason,
                'reference' => $this->reference,
                'comments' => $this->comments,
                'transferred_at' => $this->transferred_at,
            ],
            default => $base,
        };
    }
}
