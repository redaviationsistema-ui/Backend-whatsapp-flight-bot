<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartRequest extends Model
{
    public const StatusNueva = 'NUEVA';

    public const StatusPendiente = 'PENDIENTE';

    public const StatusEnAtencion = 'EN_ATENCION';

    public const StatusCerrada = 'CERRADA';

    public const StatusCancelada = 'CANCELADA';

    protected $table = 'parts_requests';

    protected $fillable = [
        'whats_app_conversation_id',
        'whats_app_contact_id',
        'part_number',
        'description',
        'quantity',
        'condition',
        'comments',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WhatsAppConversation::class, 'whats_app_conversation_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(WhatsAppContact::class, 'whats_app_contact_id');
    }
}
