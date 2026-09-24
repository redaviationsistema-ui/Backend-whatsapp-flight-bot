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
        $this->addIndexIfMissing('parts_requests', 'parts_requests_status_created_at_index', ['status', 'created_at']);
        $this->addIndexIfMissing('engine_requests', 'engine_requests_status_created_at_index', ['status', 'created_at']);
        $this->addIndexIfMissing('support_requests', 'support_requests_status_created_at_index', ['status', 'created_at']);
        $this->addIndexIfMissing('advisor_requests', 'advisor_requests_status_created_at_index', ['status', 'created_at']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->dropIndexIfExists('parts_requests', 'parts_requests_status_created_at_index');
        $this->dropIndexIfExists('engine_requests', 'engine_requests_status_created_at_index');
        $this->dropIndexIfExists('support_requests', 'support_requests_status_created_at_index');
        $this->dropIndexIfExists('advisor_requests', 'advisor_requests_status_created_at_index');
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function addIndexIfMissing(string $table, string $index, array $columns): void
    {
        if (! Schema::hasTable($table) || Schema::hasIndex($table, $index)) {
            return;
        }

        Schema::table($table, fn (Blueprint $blueprint): mixed => $blueprint->index($columns, $index));
    }

    private function dropIndexIfExists(string $table, string $index): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasIndex($table, $index)) {
            return;
        }

        Schema::table($table, fn (Blueprint $blueprint): mixed => $blueprint->dropIndex($index));
    }
};
