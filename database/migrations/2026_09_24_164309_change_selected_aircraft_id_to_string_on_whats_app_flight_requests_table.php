<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Superseded by the PostgreSQL-safe UUID conversion migration.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally irreversible: UUID values cannot be safely restored to bigint.
    }
};
