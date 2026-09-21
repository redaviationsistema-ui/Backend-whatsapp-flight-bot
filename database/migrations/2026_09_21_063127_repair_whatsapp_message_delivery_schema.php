<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Reparar whats_app_messages
        |--------------------------------------------------------------------------
        */

        if (! Schema::hasColumn('whats_app_messages', 'status')) {
            Schema::table('whats_app_messages', function (Blueprint $table): void {
                $table->string('status')->nullable();
            });
        }

        if (! Schema::hasColumn('whats_app_messages', 'delivered_at')) {
            Schema::table('whats_app_messages', function (Blueprint $table): void {
                $table->timestamp('delivered_at')->nullable();
            });
        }

        if (! Schema::hasColumn('whats_app_messages', 'read_at')) {
            Schema::table('whats_app_messages', function (Blueprint $table): void {
                $table->timestamp('read_at')->nullable();
            });
        }

        if (! Schema::hasColumn('whats_app_messages', 'failed_at')) {
            Schema::table('whats_app_messages', function (Blueprint $table): void {
                $table->timestamp('failed_at')->nullable();
            });
        }

        if (! Schema::hasColumn('whats_app_messages', 'error_code')) {
            Schema::table('whats_app_messages', function (Blueprint $table): void {
                $table->string('error_code')->nullable();
            });
        }

        if (! Schema::hasColumn('whats_app_messages', 'error_message')) {
            Schema::table('whats_app_messages', function (Blueprint $table): void {
                $table->text('error_message')->nullable();
            });
        }

        if (! Schema::hasColumn('whats_app_messages', 'processed_at')) {
            Schema::table('whats_app_messages', function (Blueprint $table): void {
                $table->timestamp('processed_at')->nullable();
            });
        }

        if (! Schema::hasColumn('whats_app_messages', 'processing_context')) {
            Schema::table('whats_app_messages', function (Blueprint $table): void {
                $table->json('processing_context')->nullable();
            });
        }

        /*
        |--------------------------------------------------------------------------
        | Mensajes inbound antiguos
        |--------------------------------------------------------------------------
        */

        DB::table('whats_app_messages')
            ->where('direction', 'inbound')
            ->whereNull('processed_at')
            ->update([
                'processed_at' => now(),
            ]);

        /*
        |--------------------------------------------------------------------------
        | Reparar tabla de statuses
        |--------------------------------------------------------------------------
        */

        if (! Schema::hasTable('whats_app_message_statuses')) {
            Schema::create('whats_app_message_statuses', function (Blueprint $table): void {
                $table->id();
                $table->string('message_id');
                $table->string('status');
                $table->timestamp('occurred_at');
                $table->string('error_code')->nullable();
                $table->text('error_message')->nullable();

                $table->unique(
                    ['message_id', 'status'],
                    'whatsapp_status_unique'
                );
            });
        }
    }

    public function down(): void
    {
        // Migración de reparación:
        // intencionalmente no eliminamos columnas ni tablas,
        // para no destruir una estructura que pudiera existir previamente.
    }
};