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
            $table->string('selected_aircraft_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whats_app_flight_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('selected_aircraft_id')->nullable()->change();
        });
    }
};
