<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whats_app_messages', function (Blueprint $table): void {
            $table->string('status')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->json('processing_context')->nullable();
            $table->index(['whats_app_conversation_id', 'sent_at', 'id'], 'whatsapp_message_history_index');
        });
        DB::table('whats_app_messages')->where('direction', 'inbound')->update(['processed_at' => now()]);
        Schema::table('whats_app_conversations', function (Blueprint $table): void {
            $table->index(['last_message_at', 'id'], 'whatsapp_conversation_recency_index');
        });
        Schema::create('whats_app_message_statuses', function (Blueprint $table): void {
            $table->id();
            $table->string('message_id');
            $table->string('status');
            $table->timestamp('occurred_at');
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->unique(['message_id', 'status'], 'whatsapp_status_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whats_app_message_statuses');
        Schema::table('whats_app_messages', function (Blueprint $table): void {
            $table->dropIndex('whatsapp_message_history_index');
            $table->dropColumn(['status', 'delivered_at', 'read_at', 'failed_at', 'error_code', 'error_message', 'processed_at', 'processing_context']);
        });
        Schema::table('whats_app_conversations', function (Blueprint $table): void {
            $table->dropIndex('whatsapp_conversation_recency_index');
        });
    }
};
