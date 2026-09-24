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
            DB::statement('
                ALTER TABLE whats_app_flight_requests
                ALTER COLUMN selected_aircraft_id DROP DEFAULT
            ');

            DB::statement('
                ALTER TABLE whats_app_flight_requests
                ALTER COLUMN selected_aircraft_id TYPE varchar(36)
                USING selected_aircraft_id::text
            ');

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
