<?php

namespace App\Services\Quotes;

use Illuminate\Database\ConnectionInterface;

class SqlQuoteDataRepository
{
    public function __construct(
        private readonly ?ConnectionInterface $database,
        private readonly bool $configured = true,
    ) {}

    /**
     * @param  array<string, mixed>  $airport
     * @return array<string, mixed>|null
     */
    public function resolveAirport(array $airport): ?array
    {
        foreach ($this->airportTables() as $table) {
            if (! $this->hasTable($table)) {
                continue;
            }

            $query = $this->database()->table($table);

            if ($value = $this->firstFilled($airport, ['airport_id', 'id'])) {
                $match = (clone $query)->where('id', $value)->first();
                if ($match) {
                    return $this->normalizeAirport((array) $match);
                }
            }

            foreach (['iata', 'icao', 'code', 'name', 'city'] as $key) {
                if (! $value = $this->firstFilled($airport, [$key])) {
                    continue;
                }

                foreach ($this->airportColumnsFor($key) as $column) {
                    if (! $this->hasColumn($table, $column)) {
                        continue;
                    }

                    $match = (clone $query)->where($column, $value)->first();
                    if ($match) {
                        return $this->normalizeAirport((array) $match);
                    }
                }
            }
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function activeAircraft(): array
    {
        $table = (string) config('quote_engine.tables.aircraft');

        if (! $this->hasTable($table)) {
            return [];
        }

        $query = $this->database()->table($table);

        if ($this->hasColumn($table, 'is_active')) {
            $query->where('is_active', true);
        }

        return $query->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function airportByAircraftBase(array $aircraft): ?array
    {
        $base = [
            'airport_id' => $this->firstFilled($aircraft, ['base_airport_id', 'home_airport_id', 'airport_id']),
            'iata' => $this->firstFilled($aircraft, ['base_iata', 'base_airport_iata', 'iata']),
            'icao' => $this->firstFilled($aircraft, ['base_icao', 'base_airport_icao', 'icao']),
            'name' => $this->firstFilled($aircraft, ['base_airport', 'base', 'home_base']),
        ];

        return array_filter($base, fn (mixed $value): bool => $value !== null && $value !== '') === []
            ? null
            : $this->resolveAirport($base);
    }

    public function isAircraftAvailable(int $aircraftId, string $startIso, string $endIso): bool
    {
        return ! $this->hasReservationConflict($aircraftId, $startIso, $endIso)
            && ! $this->hasBlockedDate($startIso, $endIso);
    }

    /**
     * @param  array<int, int|string>  $airportIds
     */
    public function worstEligibilityStatus(int $aircraftId, array $airportIds): string
    {
        $table = (string) config('quote_engine.tables.aircraft_airport_eligibilities');

        if (! $this->hasTable($table) || $airportIds === []) {
            return 'ALLOWED_WITH_VALIDATION';
        }

        $statuses = $this->database()->table($table)
            ->where('aircraft_id', $aircraftId)
            ->whereIn('airport_id', $airportIds)
            ->pluck('resultado_operacional')
            ->map(fn (mixed $status): string => strtoupper((string) $status));

        if ($statuses->contains('BLOCKED')) {
            return 'BLOCKED';
        }

        if ($statuses->contains('PERFORMANCE_REQUIRED')) {
            return 'PERFORMANCE_REQUIRED';
        }

        return 'ALLOWED_WITH_VALIDATION';
    }

    /**
     * @return array<int, string>
     */
    private function airportTables(): array
    {
        return [
            (string) config('quote_engine.tables.national_airports'),
            (string) config('quote_engine.tables.international_airports'),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function airportColumnsFor(string $key): array
    {
        return match ($key) {
            'iata', 'code' => ['iata', 'IATA', 'code', 'codigo', 'airport_code'],
            'icao' => ['icao', 'ICAO'],
            'name' => ['name', 'nombre', 'airport_name'],
            'city' => ['city', 'ciudad'],
            default => [$key],
        };
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeAirport(array $row): array
    {
        return [
            'id' => $this->firstFilled($row, ['id', 'airport_id']),
            'name' => $this->firstFilled($row, ['name', 'nombre', 'airport_name']),
            'iata' => $this->firstFilled($row, ['iata', 'IATA', 'code', 'codigo', 'airport_code']),
            'icao' => $this->firstFilled($row, ['icao', 'ICAO']),
            'city' => $this->firstFilled($row, ['city', 'ciudad']),
            'state' => $this->firstFilled($row, ['state', 'estado']),
            'country' => $this->firstFilled($row, ['country', 'pais']),
            'lat' => (float) $this->firstFilled($row, ['lat', 'latitude', 'LATITUDE'], 0),
            'lng' => (float) $this->firstFilled($row, ['lng', 'lon', 'longitude', 'LONGITUDE'], 0),
            'raw' => $row,
        ];
    }

    private function hasReservationConflict(int $aircraftId, string $startIso, string $endIso): bool
    {
        $table = (string) config('quote_engine.tables.reservations');

        if (! $this->hasTable($table)) {
            return false;
        }

        return $this->database()->table($table)
            ->where('aircraft_id', $aircraftId)
            ->whereIn('status', ['pending', 'confirmed'])
            ->where('start_datetime', '<', $endIso)
            ->where('end_datetime', '>', $startIso)
            ->exists();
    }

    private function hasBlockedDate(string $startIso, string $endIso): bool
    {
        $table = (string) config('quote_engine.tables.blocked_dates');

        if (! $this->hasTable($table)) {
            return false;
        }

        $query = $this->database()->table($table);

        if ($this->hasColumn($table, 'date')) {
            return $query->whereBetween('date', [substr($startIso, 0, 10), substr($endIso, 0, 10)])->exists();
        }

        if ($this->hasColumn($table, 'start_date') && $this->hasColumn($table, 'end_date')) {
            return $query
                ->where('start_date', '<=', substr($endIso, 0, 10))
                ->where('end_date', '>=', substr($startIso, 0, 10))
                ->exists();
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<int, string>  $keys
     */
    private function firstFilled(array $source, array $keys, mixed $default = null): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $source) && $source[$key] !== null && $source[$key] !== '') {
                return $source[$key];
            }
        }

        return $default;
    }

    private function hasTable(string $table): bool
    {
        return $this->database()->getSchemaBuilder()->hasTable($table);
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->database()->getSchemaBuilder()->hasColumn($table, $column);
    }

    private function database(): ConnectionInterface
    {
        if (! $this->configured || ! $this->database instanceof ConnectionInterface) {
            throw new QuoteDatabaseNotConfiguredException;
        }

        return $this->database;
    }
}
