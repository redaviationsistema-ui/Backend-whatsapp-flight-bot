<?php

namespace Tests\Feature;

use App\Services\Quotes\QuoteDatabaseHealth;
use App\Services\Quotes\QuoteEngine;
use App\Services\Quotes\SqlQuoteDataRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class QuotePreviewControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.quote_db' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
                'username' => 'testing',
            ],
            'quote_engine.currency' => 'USD',
            'quote_engine.tables.national_airports' => 'aeropuertos_mexico',
            'quote_engine.tables.international_airports' => 'airports_geo',
            'quote_engine.tables.aircraft' => 'aircraft_fleet',
            'quote_engine.tables.reservations' => 'reservations',
            'quote_engine.tables.blocked_dates' => 'blocked_dates',
            'quote_engine.tables.aircraft_airport_eligibilities' => 'aircraft_airport_eligibilities',
        ]);

        $this->app['db']->purge('quote_db');
        $this->createQuoteEngineTables();
    }

    public function test_preview_returns_multiple_options_with_ferry_pricing_and_range(): void
    {
        $this->seedAirports();
        $this->seedAircraft();
        $this->seedEligibility();

        $response = $this->postJson('/api/v1/client/quotes/preview', [
            'passengers' => 4,
            'trip_type' => 'one_way',
            'legs' => [[
                'origin' => ['iata' => 'TLC'],
                'destination' => ['iata' => 'CUN'],
                'departure_datetime' => '2026-10-15T14:30:00',
            ]],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('currency', 'USD')
            ->assertJsonPath('estimated_min', 27492.67)
            ->assertJsonPath('estimated_max', 31667.17)
            ->assertJsonCount(2, 'options')
            ->assertJsonPath('options.0.aircraft_id', 101)
            ->assertJsonPath('options.0.aircraft_name', 'Citation CJ3')
            ->assertJsonPath('options.0.estimated_total', 27492.67)
            ->assertJsonPath('options.0.pricing_breakdown.customer_flight_cost', 8613.33)
            ->assertJsonPath('options.0.pricing_breakdown.ferry_flight_cost', 14693.33)
            ->assertJsonPath('options.0.pricing_breakdown.airport_operational_expenses', 600)
            ->assertJsonPath('options.0.pricing_breakdown.commercial_margin', 3586)
            ->assertJsonPath('options.0.pricing_breakdown.iva', 0)
            ->assertJsonCount(1, 'options.0.customer_routes')
            ->assertJsonCount(2, 'options.0.ferry_routes');
    }

    public function test_preview_prefers_requested_aircraft_when_it_is_available(): void
    {
        $this->seedAirports();
        $this->seedAircraft();
        $this->seedEligibility();

        $response = $this->postJson('/api/v1/client/quotes/preview', [
            'passengers' => 4,
            'aircraft_preference_id' => 202,
            'legs' => [[
                'origin' => ['iata' => 'TLC'],
                'destination' => ['iata' => 'CUN'],
                'departure_datetime' => '2026-10-15T14:30:00',
            ]],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('options.0.aircraft_id', 202)
            ->assertJsonPath('options.0.estimated_total', 31667.17);
    }

    public function test_preview_excludes_reserved_and_operationally_blocked_aircraft(): void
    {
        $this->seedAirports();
        $this->seedAircraft();
        $this->seedEligibility();
        $this->insertReservation(101);
        $this->insertBlockedEligibility(202, 2);

        $response = $this->postJson('/api/v1/client/quotes/preview', [
            'passengers' => 4,
            'legs' => [[
                'origin' => ['iata' => 'TLC'],
                'destination' => ['iata' => 'CUN'],
                'departure_datetime' => '2026-10-15T14:30:00',
            ]],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('estimated_min', null)
            ->assertJsonPath('estimated_max', null)
            ->assertJsonCount(0, 'options');
    }

    public function test_preview_returns_422_when_airport_cannot_be_resolved(): void
    {
        $this->seedAirports();
        $this->seedAircraft();

        $response = $this->postJson('/api/v1/client/quotes/preview', [
            'passengers' => 4,
            'legs' => [[
                'origin' => ['iata' => 'XXX'],
                'destination' => ['iata' => 'CUN'],
                'departure_datetime' => '2026-10-15T14:30:00',
            ]],
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('code', 'AIRPORT_NOT_RESOLVED')
            ->assertJsonCount(0, 'options');
    }

    public function test_preview_resolves_mexico_airports_by_uppercase_iata_and_icao_columns(): void
    {
        $this->seedAirports();
        $this->seedAircraft();
        $this->seedEligibility();
        $this->quoteDb()->flushQueryLog();
        $this->quoteDb()->enableQueryLog();

        $response = $this->postJson('/api/v1/client/quotes/preview', [
            'passengers' => 4,
            'legs' => [[
                'origin' => ['iata' => 'MMTO'],
                'destination' => ['icao' => 'MMMY'],
                'departure_datetime' => '2026-10-15T14:30:00',
            ]],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('options.0.customer_routes.0.origin.iata', 'TLC')
            ->assertJsonPath('options.0.customer_routes.0.origin.icao', 'MMTO')
            ->assertJsonPath('options.0.customer_routes.0.origin.lat', 19.3371)
            ->assertJsonPath('options.0.customer_routes.0.origin.lng', -99.566)
            ->assertJsonPath('options.0.customer_routes.0.origin.elevation_ft', 8466)
            ->assertJsonPath('options.0.customer_routes.0.destination.iata', 'MTY')
            ->assertJsonPath('options.0.customer_routes.0.destination.icao', 'MMMY');

        $queries = collect($this->quoteDb()->getQueryLog())
            ->pluck('query')
            ->filter(fn (string $query): bool => str_contains($query, 'aeropuertos_mexico'))
            ->implode("\n");

        $this->assertStringContainsString('"IATA"', $queries);
        $this->assertStringContainsString('"ICAO"', $queries);
        $this->assertStringNotContainsString('"iata"', $queries);
        $this->quoteDb()->disableQueryLog();
    }

    public function test_preview_resolves_mty_by_uppercase_iata_column(): void
    {
        $this->seedAirports();
        $this->seedAircraft();
        $this->seedEligibility();

        $response = $this->postJson('/api/v1/client/quotes/preview', [
            'passengers' => 4,
            'legs' => [[
                'origin' => ['iata' => 'MTY'],
                'destination' => ['iata' => 'CUN'],
                'departure_datetime' => '2026-10-15T14:30:00',
            ]],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('options.0.customer_routes.0.origin.iata', 'MTY');
    }

    public function test_preview_keeps_aircraft_fleet_iata_lowercase_for_base_airport_lookup(): void
    {
        $this->seedAirports();
        $this->seedEligibility();
        $this->quoteDb()->table('aircraft_fleet')->insert([
            'id' => 404,
            'name' => 'Base IATA Jet',
            'aircraft_type' => 'JET LIGERO (LIGHT JET)',
            'capacity_passengers' => 7,
            'range_nm' => 1800,
            'cruise_speed_knots' => 410,
            'rental_price_usd' => 3800,
            'iata' => 'MTY',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/client/quotes/preview', [
            'passengers' => 4,
            'legs' => [[
                'origin' => ['iata' => 'TLC'],
                'destination' => ['iata' => 'CUN'],
                'departure_datetime' => '2026-10-15T14:30:00',
            ]],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('options.0.aircraft_id', 404)
            ->assertJsonPath('options.0.ferry_routes.0.origin.iata', 'MTY');
    }

    public function test_preview_filters_aircraft_by_capacity_range_runway_and_elevation(): void
    {
        $this->seedAirports();
        $this->quoteDb()->table('aircraft_fleet')->insert([
            [
                'id' => 501,
                'name' => 'Operational Match',
                'aircraft_type' => 'JET LIGERO (LIGHT JET)',
                'capacity_passengers' => 6,
                'range_nm' => 1800,
                'cruise_speed_knots' => 410,
                'rental_price_usd' => 3800,
                'base_airport_id' => 3,
                'minimum_runway_m' => 2500,
                'max_airport_elevation_ft' => 9000,
                'is_active' => true,
            ],
            [
                'id' => 502,
                'name' => 'Too Small',
                'aircraft_type' => 'JET LIGERO (LIGHT JET)',
                'capacity_passengers' => 3,
                'range_nm' => 1800,
                'cruise_speed_knots' => 410,
                'rental_price_usd' => 3800,
                'base_airport_id' => 3,
                'minimum_runway_m' => 2500,
                'max_airport_elevation_ft' => 9000,
                'is_active' => true,
            ],
            [
                'id' => 503,
                'name' => 'Too Short Range',
                'aircraft_type' => 'JET LIGERO (LIGHT JET)',
                'capacity_passengers' => 6,
                'range_nm' => 100,
                'cruise_speed_knots' => 410,
                'rental_price_usd' => 3800,
                'base_airport_id' => 3,
                'minimum_runway_m' => 2500,
                'max_airport_elevation_ft' => 9000,
                'is_active' => true,
            ],
            [
                'id' => 504,
                'name' => 'Needs Longer Runway',
                'aircraft_type' => 'JET LIGERO (LIGHT JET)',
                'capacity_passengers' => 6,
                'range_nm' => 1800,
                'cruise_speed_knots' => 410,
                'rental_price_usd' => 3800,
                'base_airport_id' => 3,
                'minimum_runway_m' => 4000,
                'max_airport_elevation_ft' => 9000,
                'is_active' => true,
            ],
            [
                'id' => 505,
                'name' => 'Too Low Elevation Limit',
                'aircraft_type' => 'JET LIGERO (LIGHT JET)',
                'capacity_passengers' => 6,
                'range_nm' => 1800,
                'cruise_speed_knots' => 410,
                'rental_price_usd' => 3800,
                'base_airport_id' => 3,
                'minimum_runway_m' => 2500,
                'max_airport_elevation_ft' => 8000,
                'is_active' => true,
            ],
        ]);

        $response = $this->postJson('/api/v1/client/quotes/preview', [
            'passengers' => 5,
            'legs' => [[
                'origin' => ['iata' => 'TLC'],
                'destination' => ['iata' => 'CUN'],
                'departure_datetime' => '2026-10-15T14:30:00',
            ]],
        ]);

        $response
            ->assertOk()
            ->assertJsonCount(1, 'options')
            ->assertJsonPath('options.0.aircraft_id', 501);
    }

    public function test_quote_engine_uses_quote_db_without_touching_default_aircraft_table(): void
    {
        $this->seedAirports();
        $this->seedAircraft();
        $this->seedEligibility();

        $this->assertFalse(Schema::hasTable('aircraft_fleet'));
        $this->assertTrue($this->quoteDb()->getSchemaBuilder()->hasTable('aircraft_fleet'));

        $response = $this->postJson('/api/v1/client/quotes/preview', [
            'passengers' => 4,
            'legs' => [[
                'origin' => ['iata' => 'TLC'],
                'destination' => ['iata' => 'CUN'],
                'departure_datetime' => '2026-10-15T14:30:00',
            ]],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('options.0.aircraft_id', 101);
    }

    public function test_preview_fails_fast_when_quote_db_is_not_configured(): void
    {
        config([
            'database.connections.quote_db.url' => null,
            'database.connections.quote_db.database' => null,
            'database.connections.quote_db.username' => null,
        ]);

        $this->app->forgetInstance(QuoteEngine::class);
        $this->app->forgetInstance(SqlQuoteDataRepository::class);

        $response = $this->postJson('/api/v1/client/quotes/preview', [
            'passengers' => 4,
            'legs' => [[
                'origin' => ['iata' => 'TLC'],
                'destination' => ['iata' => 'CUN'],
                'departure_datetime' => '2026-10-15T14:30:00',
            ]],
        ]);

        $response
            ->assertServiceUnavailable()
            ->assertJsonPath('code', 'QUOTE_DATABASE_NOT_CONFIGURED');
    }

    public function test_quote_db_health_reports_required_tables(): void
    {
        $health = new QuoteDatabaseHealth($this->quoteDb());

        $this->assertSame([
            'aircraft_fleet' => true,
            'aeropuertos_mexico' => true,
            'airports_geo' => true,
            'blocked_dates' => true,
            'reservations' => true,
        ], $health->check()['tables']);
    }

    private function createQuoteEngineTables(): void
    {
        $schema = $this->quoteDb()->getSchemaBuilder();

        $schema->create('aeropuertos_mexico', function (Blueprint $table): void {
            $table->unsignedInteger('ID')->primary();
            $table->string('AEROPUERTO')->nullable();
            $table->string('CIUDAD')->nullable();
            $table->string('ESTADO')->nullable();
            $table->string('COUNTRY')->nullable();
            $table->string('IATA')->nullable();
            $table->string('ICAO')->nullable();
            $table->decimal('LATITUDE', 10, 6);
            $table->decimal('LONGITUDE', 10, 6);
            $table->unsignedInteger('ELEVATION_FT')->nullable();
            $table->unsignedInteger('runway_length_m')->nullable();
        });

        $schema->create('airports_geo', function (Blueprint $table): void {
            $table->id();
            $table->string('iata')->nullable();
            $table->string('icao')->nullable();
            $table->string('name')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->nullable();
            $table->decimal('lat', 10, 6);
            $table->decimal('lng', 10, 6);
        });

        $schema->create('aircraft_fleet', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('aircraft_type');
            $table->unsignedSmallInteger('capacity_passengers');
            $table->unsignedInteger('range_nm');
            $table->unsignedInteger('cruise_speed_knots');
            $table->unsignedInteger('rental_price_usd');
            $table->foreignId('base_airport_id')->nullable();
            $table->string('iata')->nullable();
            $table->string('base_city')->nullable();
            $table->unsignedInteger('minimum_runway_m')->nullable();
            $table->unsignedInteger('max_airport_elevation_ft')->nullable();
            $table->boolean('performance_validation_required')->default(false);
            $table->unsignedInteger('airport_fees_usd')->default(0);
            $table->unsignedInteger('overnight_fee_usd')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('estado')->nullable();
        });

        $schema->create('reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('aircraft_id');
            $table->string('status');
            $table->dateTime('start_datetime');
            $table->dateTime('end_datetime');
        });

        $schema->create('blocked_dates', function (Blueprint $table): void {
            $table->id();
            $table->date('date');
        });

        $schema->create('aircraft_airport_eligibilities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('aircraft_id');
            $table->foreignId('airport_id');
            $table->string('resultado_operacional');
        });
    }

    private function seedAirports(): void
    {
        $airports = [
            ['ID' => 1, 'IATA' => 'TLC', 'ICAO' => 'MMTO', 'AEROPUERTO' => 'Toluca', 'CIUDAD' => 'Toluca', 'ESTADO' => 'Estado de México', 'COUNTRY' => 'MX', 'LATITUDE' => 19.337100, 'LONGITUDE' => -99.566000, 'ELEVATION_FT' => 8466, 'runway_length_m' => 4200],
            ['ID' => 2, 'IATA' => 'CUN', 'ICAO' => 'MMUN', 'AEROPUERTO' => 'Cancun', 'CIUDAD' => 'Cancun', 'ESTADO' => 'Quintana Roo', 'COUNTRY' => 'MX', 'LATITUDE' => 21.036500, 'LONGITUDE' => -86.877100, 'ELEVATION_FT' => 22, 'runway_length_m' => 3500],
            ['ID' => 3, 'IATA' => 'MTY', 'ICAO' => 'MMMY', 'AEROPUERTO' => 'Monterrey', 'CIUDAD' => 'Monterrey', 'ESTADO' => 'Nuevo León', 'COUNTRY' => 'MX', 'LATITUDE' => 25.778500, 'LONGITUDE' => -100.107000, 'ELEVATION_FT' => 1278, 'runway_length_m' => 3000],
        ];

        foreach ($airports as $airport) {
            $this->quoteDb()->table('aeropuertos_mexico')->insert($airport);
        }
    }

    private function seedAircraft(): void
    {
        $this->quoteDb()->table('aircraft_fleet')->insert([
            [
                'id' => 101,
                'name' => 'Citation CJ3',
                'aircraft_type' => 'JET LIGERO (LIGHT JET)',
                'capacity_passengers' => 7,
                'range_nm' => 1800,
                'cruise_speed_knots' => 410,
                'rental_price_usd' => 3800,
                'base_airport_id' => 3,
                'airport_fees_usd' => 600,
                'overnight_fee_usd' => 650,
                'is_active' => true,
            ],
            [
                'id' => 202,
                'name' => 'Hawker 800XP',
                'aircraft_type' => 'MIDSIZE JET (MID JET)',
                'capacity_passengers' => 8,
                'range_nm' => 2500,
                'cruise_speed_knots' => 430,
                'rental_price_usd' => 6100,
                'base_airport_id' => 1,
                'airport_fees_usd' => 900,
                'overnight_fee_usd' => 850,
                'is_active' => true,
            ],
            [
                'id' => 303,
                'name' => 'Tiny Jet',
                'aircraft_type' => 'JET LIGERO (LIGHT JET)',
                'capacity_passengers' => 3,
                'range_nm' => 1800,
                'cruise_speed_knots' => 410,
                'rental_price_usd' => 2500,
                'base_airport_id' => 1,
                'airport_fees_usd' => 400,
                'overnight_fee_usd' => 650,
                'is_active' => true,
            ],
        ]);
    }

    private function seedEligibility(): void
    {
        foreach ([101, 202, 303] as $aircraftId) {
            foreach ([1, 2, 3] as $airportId) {
                $this->quoteDb()->table('aircraft_airport_eligibilities')->insert([
                    'aircraft_id' => $aircraftId,
                    'airport_id' => $airportId,
                    'resultado_operacional' => 'ALLOWED_WITH_VALIDATION',
                ]);
            }
        }
    }

    private function insertReservation(int $aircraftId): void
    {
        $this->quoteDb()->table('reservations')->insert([
            'aircraft_id' => $aircraftId,
            'status' => 'confirmed',
            'start_datetime' => '2026-10-15T12:00:00',
            'end_datetime' => '2026-10-15T20:00:00',
        ]);
    }

    private function insertBlockedEligibility(int $aircraftId, int $airportId): void
    {
        $this->quoteDb()->table('aircraft_airport_eligibilities')
            ->where('aircraft_id', $aircraftId)
            ->where('airport_id', $airportId)
            ->update(['resultado_operacional' => 'BLOCKED']);
    }

    private function quoteDb(): SQLiteConnection
    {
        /** @var SQLiteConnection $connection */
        $connection = $this->app['db']->connection('quote_db');

        return $connection;
    }
}
