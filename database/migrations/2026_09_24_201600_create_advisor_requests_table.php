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
        Schema::create('advisor_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whats_app_conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whats_app_contact_id')->constrained()->cascadeOnDelete();
            $table->string('reason');
            $table->string('reference')->nullable();
            $table->text('comments');
            $table->string('status')->default('NUEVA');
            $table->timestamp('transferred_at')->nullable();
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
        Schema::dropIfExists('advisor_requests');
    }
};
