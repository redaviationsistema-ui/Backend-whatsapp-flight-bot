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
        Schema::create('whats_app_flight_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whats_app_conversation_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('origin')->nullable();
            $table->string('destination')->nullable();
            $table->date('departure_date')->nullable();
            $table->time('departure_time')->nullable();
            $table->unsignedSmallInteger('passengers')->nullable();
            $table->string('trip_type')->nullable();
            $table->date('return_date')->nullable();
            $table->time('return_time')->nullable();
            $table->json('search_results')->nullable();
            $table->string('selected_aircraft')->nullable();
            $table->string('quote_reference')->nullable();
            $table->string('status')->default('collecting')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whats_app_flight_requests');
    }
};
