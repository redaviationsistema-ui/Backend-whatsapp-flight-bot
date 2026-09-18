<?php

namespace App\Models;

use Database\Factories\WhatsAppFlightRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppFlightRequest extends Model
{
    /** @use HasFactory<WhatsAppFlightRequestFactory> */
    use HasFactory;

    protected $fillable = [
        'whats_app_conversation_id',
        'origin',
        'destination',
        'departure_date',
        'departure_time',
        'passengers',
        'trip_type',
        'return_date',
        'return_time',
        'search_results',
        'selected_aircraft',
        'selected_aircraft_id',
        'selected_provider_id',
        'selected_match_id',
        'quote_reference',
        'backend_flight_request_id',
        'accepted_quote_id',
        'official_quote_payload',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'departure_date' => 'date',
            'passengers' => 'integer',
            'return_date' => 'date',
            'search_results' => 'array',
            'selected_aircraft_id' => 'integer',
            'selected_provider_id' => 'integer',
            'backend_flight_request_id' => 'integer',
            'accepted_quote_id' => 'integer',
            'official_quote_payload' => 'array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WhatsAppConversation::class, 'whats_app_conversation_id');
    }
}
