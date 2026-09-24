<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE whats_app_flight_requests
                ALTER COLUMN selected_aircraft_id DROP DEFAULT,
                ALTER COLUMN selected_aircraft_id TYPE uuid
                USING CASE
                    WHEN selected_aircraft_id IS NULL THEN NULL
                    WHEN selected_aircraft_id::text ~* '^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'
                        THEN selected_aircraft_id::text::uuid
                    ELSE NULL
                END
            SQL);

            return;
        }

        Schema::table('whats_app_flight_requests', function (Blueprint $table): void {
            $table->string('selected_aircraft_id', 36)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally irreversible: UUID values cannot be safely restored to bigint.
    }
};
