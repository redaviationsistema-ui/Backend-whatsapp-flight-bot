<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('whats_app_message_statuses')) {
            Schema::create('whats_app_message_statuses', function (Blueprint $table): void {
                $table->id();
                $table->string('message_id');
                $table->string('status');
                $table->timestamp('occurred_at')->nullable();
                $table->string('error_code')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamps();

                $table->index('message_id');
                $table->index('status');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('whats_app_message_statuses');
    }
};