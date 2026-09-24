<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportRequest extends Model
{
    public const StatusNueva = 'NUEVA';

    public const StatusPendiente = 'PENDIENTE';

    public const StatusEnAtencion = 'EN_ATENCION';

    public const StatusCerrada = 'CERRADA';

    public const StatusCancelada = 'CANCELADA';

    protected $fillable = [
        'whats_app_conversation_id',
        'whats_app_contact_id',
        'reason',
        'reference',
        'description',
        'priority',
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
