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
            $table->id();
            $table->string('iata')->nullable();
            $table->string('icao')->nullable();
            $table->string('nombre')->nullable();
            $table->string('ciudad')->nullable();
            $table->string('estado')->nullable();
            $table->string('country')->nullable();
            $table->decimal('lat', 10, 6);
            $table->decimal('lng', 10, 6);
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
            $table->foreignId('base_airport_id');
            $table->unsignedInteger('airport_fees_usd')->default(0);
            $table->unsignedInteger('overnight_fee_usd')->default(0);
            $table->boolean('is_active')->default(true);
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
            ['id' => 1, 'iata' => 'TLC', 'icao' => 'MMTO', 'nombre' => 'Toluca', 'ciudad' => 'Toluca', 'estado' => 'Estado de México', 'country' => 'MX', 'lat' => 19.337100, 'lng' => -99.566000],
            ['id' => 2, 'iata' => 'CUN', 'icao' => 'MMUN', 'nombre' => 'Cancun', 'ciudad' => 'Cancun', 'estado' => 'Quintana Roo', 'country' => 'MX', 'lat' => 21.036500, 'lng' => -86.877100],
            ['id' => 3, 'iata' => 'MTY', 'icao' => 'MMMY', 'nombre' => 'Monterrey', 'ciudad' => 'Monterrey', 'estado' => 'Nuevo León', 'country' => 'MX', 'lat' => 25.778500, 'lng' => -100.107000],
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
