<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whats_app_flight_requests', function (Blueprint $table): void {
            $table->boolean('is_time_flexible')->nullable();
            $table->unsignedSmallInteger('luggage_count')->nullable();
            $table->text('luggage_description')->nullable();
            $table->text('special_luggage')->nullable();
            $table->boolean('has_pets')->nullable();
            $table->text('pets_description')->nullable();
            $table->string('aircraft_preference')->nullable();
            $table->boolean('allow_alternate_airports')->nullable();
            $table->boolean('catering_required')->nullable();
            $table->boolean('ground_transport_required')->nullable();
            $table->boolean('wifi_required')->nullable();
            $table->text('other_services')->nullable();
            $table->string('client_name')->nullable();
            $table->string('client_email')->nullable();
            $table->string('company')->nullable();
            $table->string('budget')->nullable();
            $table->text('notes')->nullable();
            $table->json('legs')->nullable();
            $table->timestamp('confirmed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('whats_app_flight_requests', function (Blueprint $table): void {
            $table->dropColumn(['is_time_flexible', 'luggage_count', 'luggage_description', 'special_luggage', 'has_pets', 'pets_description', 'aircraft_preference', 'allow_alternate_airports', 'catering_required', 'ground_transport_required', 'wifi_required', 'other_services', 'client_name', 'client_email', 'company', 'budget', 'notes', 'legs', 'confirmed_at']);
        });
    }
};
