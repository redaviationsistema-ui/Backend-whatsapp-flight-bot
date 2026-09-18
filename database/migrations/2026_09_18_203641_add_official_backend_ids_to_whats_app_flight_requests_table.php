<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('whats_app_flight_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('backend_flight_request_id')->nullable()->after('quote_reference')->index();
            $table->unsignedBigInteger('accepted_quote_id')->nullable()->after('backend_flight_request_id')->index();
            $table->unsignedBigInteger('selected_aircraft_id')->nullable()->after('selected_aircraft')->index();
            $table->unsignedBigInteger('selected_provider_id')->nullable()->after('selected_aircraft_id')->index();
            $table->string('selected_match_id')->nullable()->after('selected_provider_id');
            $table->json('official_quote_payload')->nullable()->after('accepted_quote_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whats_app_flight_requests', function (Blueprint $table) {
            $table->dropColumn([
                'backend_flight_request_id',
                'accepted_quote_id',
                'selected_aircraft_id',
                'selected_provider_id',
                'selected_match_id',
                'official_quote_payload',
            ]);
        });
    }
};
