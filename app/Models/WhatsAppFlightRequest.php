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
        'is_time_flexible',
        'luggage_count',
        'luggage_description',
        'special_luggage',
        'has_pets',
        'pets_description',
        'aircraft_preference',
        'allow_alternate_airports',
        'catering_required',
        'ground_transport_required',
        'wifi_required',
        'other_services',
        'client_name',
        'client_email',
        'company',
        'budget',
        'notes',
        'legs',
        'confirmed_at',

    ];

    protected function casts(): array
    {
        return [
            'departure_date' => 'date:Y-m-d',
            'passengers' => 'integer',
            'return_date' => 'date:Y-m-d',
            'search_results' => 'array',
            'selected_aircraft_id' => 'integer',
            'selected_provider_id' => 'integer',
            'backend_flight_request_id' => 'integer',
            'accepted_quote_id' => 'integer',
            'official_quote_payload' => 'array',
            'is_time_flexible' => 'boolean',
            'luggage_count' => 'integer',
            'has_pets' => 'boolean',
            'allow_alternate_airports' => 'boolean',
            'catering_required' => 'boolean',
            'ground_transport_required' => 'boolean',
            'wifi_required' => 'boolean',
            'legs' => 'array',
            'confirmed_at' => 'datetime',

        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WhatsAppConversation::class, 'whats_app_conversation_id');
    }
}
