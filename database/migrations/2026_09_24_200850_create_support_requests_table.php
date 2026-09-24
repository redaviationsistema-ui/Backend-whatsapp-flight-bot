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
        Schema::create('support_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whats_app_conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whats_app_contact_id')->constrained()->cascadeOnDelete();
            $table->string('reason');
            $table->string('reference')->nullable();
            $table->text('description');
            $table->string('priority');
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
        Schema::dropIfExists('support_requests');
    }
};
