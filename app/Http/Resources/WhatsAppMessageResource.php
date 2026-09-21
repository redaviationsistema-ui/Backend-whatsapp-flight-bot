<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WhatsAppMessageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return $this->resource->only(['id', 'direction', 'type', 'body', 'message_id', 'sent_at', 'status', 'delivered_at', 'read_at', 'failed_at', 'error_code', 'error_message']);
    }
}
