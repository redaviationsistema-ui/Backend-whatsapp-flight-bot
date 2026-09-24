<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addColumnIfMissing('whats_app_messages', 'status', fn (Blueprint $table) => $table->string('status')->nullable());
        $this->addColumnIfMissing('whats_app_messages', 'delivered_at', fn (Blueprint $table) => $table->timestamp('delivered_at')->nullable());
        $this->addColumnIfMissing('whats_app_messages', 'read_at', fn (Blueprint $table) => $table->timestamp('read_at')->nullable());
        $this->addColumnIfMissing('whats_app_messages', 'failed_at', fn (Blueprint $table) => $table->timestamp('failed_at')->nullable());
        $this->addColumnIfMissing('whats_app_messages', 'error_code', fn (Blueprint $table) => $table->string('error_code')->nullable());
        $this->addColumnIfMissing('whats_app_messages', 'error_message', fn (Blueprint $table) => $table->text('error_message')->nullable());
        $this->addColumnIfMissing('whats_app_messages', 'processed_at', fn (Blueprint $table) => $table->timestamp('processed_at')->nullable());
        $this->addColumnIfMissing('whats_app_messages', 'processing_context', fn (Blueprint $table) => $table->json('processing_context')->nullable());

        $this->addIndexIfMissing('whats_app_messages', 'whatsapp_message_history_index', fn (Blueprint $table) => $table->index(['whats_app_conversation_id', 'sent_at', 'id'], 'whatsapp_message_history_index'));

        if (Schema::hasColumn('whats_app_messages', 'processed_at')) {
            DB::table('whats_app_messages')->where('direction', 'inbound')->update(['processed_at' => now()]);
        }

        $this->addIndexIfMissing('whats_app_conversations', 'whatsapp_conversation_recency_index', fn (Blueprint $table) => $table->index(['last_message_at', 'id'], 'whatsapp_conversation_recency_index'));

        if (! Schema::hasTable('whats_app_message_statuses')) {
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
    }

    public function down(): void
    {
        Schema::dropIfExists('whats_app_message_statuses');

        if (Schema::hasIndex('whats_app_messages', 'whatsapp_message_history_index')) {
            Schema::table('whats_app_messages', function (Blueprint $table): void {
                $table->dropIndex('whatsapp_message_history_index');
            });
        }

        $messageColumns = array_filter(
            ['status', 'delivered_at', 'read_at', 'failed_at', 'error_code', 'error_message', 'processed_at', 'processing_context'],
            fn (string $column): bool => Schema::hasColumn('whats_app_messages', $column),
        );

        if ($messageColumns !== []) {
            Schema::table('whats_app_messages', function (Blueprint $table) use ($messageColumns): void {
                $table->dropColumn($messageColumns);
            });
        }

        if (Schema::hasIndex('whats_app_conversations', 'whatsapp_conversation_recency_index')) {
            Schema::table('whats_app_conversations', function (Blueprint $table): void {
                $table->dropIndex('whatsapp_conversation_recency_index');
            });
        }
    }

    private function addColumnIfMissing(string $tableName, string $column, callable $definition): void
    {
        if (Schema::hasColumn($tableName, $column)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($definition): void {
            $definition($table);
        });
    }

    private function addIndexIfMissing(string $tableName, string $index, callable $definition): void
    {
        if (Schema::hasIndex($tableName, $index)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($definition): void {
            $definition($table);
        });
    }
};
