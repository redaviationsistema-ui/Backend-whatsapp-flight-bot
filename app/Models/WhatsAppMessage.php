<?php

namespace App\Models;

use Database\Factories\WhatsAppMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppMessage extends Model
{
    /** @use HasFactory<WhatsAppMessageFactory> */
    use HasFactory;

    protected $fillable = [
        'whats_app_conversation_id',
        'message_id',
        'direction',
        'type',
        'body',
        'payload',
        'sent_at',
        'status',
        'delivered_at',
        'read_at',
        'failed_at',
        'error_code',
        'error_message',
        'processed_at',
        'processing_context',

    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'failed_at' => 'datetime',
            'processed_at' => 'datetime',
            'processing_context' => 'array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WhatsAppConversation::class, 'whats_app_conversation_id');
    }
}
