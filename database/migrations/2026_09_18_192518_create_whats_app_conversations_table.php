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
        Schema::create('whats_app_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whats_app_contact_id')->constrained()->cascadeOnDelete();
            $table->string('state')->default('START')->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('transferred_to_human_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whats_app_conversations');
    }
};
