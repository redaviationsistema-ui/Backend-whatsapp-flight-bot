<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EngineRequest extends Model
{
    public const StatusNueva = 'NUEVA';

    public const StatusPendiente = 'PENDIENTE';

    public const StatusEnAtencion = 'EN_ATENCION';

    public const StatusCerrada = 'CERRADA';

    public const StatusCancelada = 'CANCELADA';

    protected $fillable = [
        'whats_app_conversation_id',
        'whats_app_contact_id',
        'engine_model',
        'part_number',
        'serial_number',
        'condition',
        'service_type',
        'comments',
        'status',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WhatsAppConversation::class, 'whats_app_conversation_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(WhatsAppContact::class, 'whats_app_contact_id');
    }
}
