<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addColumnIfMissing('is_time_flexible', fn (Blueprint $table) => $table->boolean('is_time_flexible')->nullable());
        $this->addColumnIfMissing('luggage_count', fn (Blueprint $table) => $table->unsignedSmallInteger('luggage_count')->nullable());
        $this->addColumnIfMissing('luggage_description', fn (Blueprint $table) => $table->text('luggage_description')->nullable());
        $this->addColumnIfMissing('special_luggage', fn (Blueprint $table) => $table->text('special_luggage')->nullable());
        $this->addColumnIfMissing('has_pets', fn (Blueprint $table) => $table->boolean('has_pets')->nullable());
        $this->addColumnIfMissing('pets_description', fn (Blueprint $table) => $table->text('pets_description')->nullable());
        $this->addColumnIfMissing('aircraft_preference', fn (Blueprint $table) => $table->string('aircraft_preference')->nullable());
        $this->addColumnIfMissing('allow_alternate_airports', fn (Blueprint $table) => $table->boolean('allow_alternate_airports')->nullable());
        $this->addColumnIfMissing('catering_required', fn (Blueprint $table) => $table->boolean('catering_required')->nullable());
        $this->addColumnIfMissing('ground_transport_required', fn (Blueprint $table) => $table->boolean('ground_transport_required')->nullable());
        $this->addColumnIfMissing('wifi_required', fn (Blueprint $table) => $table->boolean('wifi_required')->nullable());
        $this->addColumnIfMissing('other_services', fn (Blueprint $table) => $table->text('other_services')->nullable());
        $this->addColumnIfMissing('client_name', fn (Blueprint $table) => $table->string('client_name')->nullable());
        $this->addColumnIfMissing('client_email', fn (Blueprint $table) => $table->string('client_email')->nullable());
        $this->addColumnIfMissing('company', fn (Blueprint $table) => $table->string('company')->nullable());
        $this->addColumnIfMissing('budget', fn (Blueprint $table) => $table->string('budget')->nullable());
        $this->addColumnIfMissing('notes', fn (Blueprint $table) => $table->text('notes')->nullable());
        $this->addColumnIfMissing('legs', fn (Blueprint $table) => $table->json('legs')->nullable());
        $this->addColumnIfMissing('confirmed_at', fn (Blueprint $table) => $table->timestamp('confirmed_at')->nullable());
    }

    public function down(): void
    {
        $columns = array_filter(
            ['is_time_flexible', 'luggage_count', 'luggage_description', 'special_luggage', 'has_pets', 'pets_description', 'aircraft_preference', 'allow_alternate_airports', 'catering_required', 'ground_transport_required', 'wifi_required', 'other_services', 'client_name', 'client_email', 'company', 'budget', 'notes', 'legs', 'confirmed_at'],
            fn (string $column): bool => Schema::hasColumn('whats_app_flight_requests', $column),
        );

        if ($columns === []) {
            return;
        }

        Schema::table('whats_app_flight_requests', function (Blueprint $table) use ($columns): void {
            $table->dropColumn($columns);
        });
    }

    private function addColumnIfMissing(string $column, callable $definition): void
    {
        if (Schema::hasColumn('whats_app_flight_requests', $column)) {
            return;
        }

        Schema::table('whats_app_flight_requests', function (Blueprint $table) use ($definition): void {
            $definition($table);
        });
    }
};
