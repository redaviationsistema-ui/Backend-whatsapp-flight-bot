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
        Schema::create('parts_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whats_app_conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whats_app_contact_id')->constrained()->cascadeOnDelete();
            $table->string('part_number');
            $table->string('description')->nullable();
            $table->unsignedInteger('quantity');
            $table->string('condition');
            $table->text('comments')->nullable();
            $table->string('status')->default('NUEVA');
            $table->timestamps();

            $table->index(['whats_app_conversation_id', 'created_at']);
            $table->index(['whats_app_contact_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('parts_requests');
    }
};
