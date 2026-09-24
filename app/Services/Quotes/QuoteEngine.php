<?php

namespace App\Services\Quotes;

use DateTimeImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

class QuoteEngine
{
    public function __construct(private readonly SqlQuoteDataRepository $repository) {}

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    public function preview(array $request): array
    {
        $legs = $this->normalizeLegs($request);
        $passengers = (int) ($request['passengers'] ?? 0);

        if ($passengers <= 0) {
            throw new InvalidArgumentException('INVALID_ROUTE: passengers must be greater than zero.');
        }

        $aircraft = collect($this->repository->activeAircraft());
        $preferredAircraftId = (string) ($request['aircraft_preference_id'] ?? '');

        $options = $aircraft
            ->map(fn (array $aircraft): ?array => $this->buildOption($aircraft, $legs, $passengers))
            ->filter()
            ->sortBy([
                fn (array $left, array $right): int => $this->preferredSort($left, $right, $preferredAircraftId),
                ['estimated_total', 'asc'],
            ])
            ->take((int) ($request['limit'] ?? 8))
            ->values()
            ->all();

        return [
            'status' => 'ok',
            'currency' => (string) config('quote_engine.currency', 'USD'),
            'estimated_min' => $options === [] ? null : min(array_column($options, 'estimated_total')),
            'estimated_max' => $options === [] ? null : max(array_column($options, 'estimated_total')),
            'options' => $options,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $legs
     * @return array<string, mixed>|null
     */
    private function buildOption(array $aircraft, array $legs, int $passengers): ?array
    {
        $aircraftId = (string) $this->firstFilled($aircraft, ['id', 'aircraft_id'], '');

        if (! Str::isUuid($aircraftId) || $this->aircraftCapacity($aircraft) < $passengers) {
            return null;
        }

        if (! $this->aircraftRangeAllows($aircraft, $legs)) {
            return null;
        }

        if (! $this->aircraftAirportPerformanceAllows($aircraft, $legs)) {
            return null;
        }

        $airportIds = collect($legs)
            ->flatMap(fn (array $leg): array => [$leg['origin']['id'], $leg['destination']['id']])
            ->filter()
            ->unique()
            ->values()
            ->all();
        $eligibilityStatus = $this->repository->worstEligibilityStatus($aircraftId, $airportIds);

        if ($eligibilityStatus === 'BLOCKED') {
            return null;
        }

        $operationalRoutes = $this->buildOperationalRoutes($aircraft, $legs);
        $breakdowns = [];

        foreach ($operationalRoutes as $index => $route) {
            $breakdowns[] = $this->calculateRoutePrice($aircraft, $route, $index, $operationalRoutes);
        }

        $bounds = $this->reservationBounds($operationalRoutes, $breakdowns);
        $available = $this->repository->isAircraftAvailable($aircraftId, $bounds['start'], $bounds['end']);

        if (! $available) {
            return null;
        }

        $pricing = $this->summarizePricing($aircraft, $operationalRoutes, $breakdowns);

        return [
            'aircraft_id' => $aircraftId,
            'aircraft_name' => (string) $this->firstFilled($aircraft, ['aircraft_name', 'name', 'model'], 'Aeronave disponible'),
            'category' => $this->firstFilled($aircraft, ['aircraft_type', 'type', 'category']),
            'capacity' => $this->aircraftCapacity($aircraft),
            'passenger_capacity' => $this->aircraftCapacity($aircraft),
            'availability' => 'available',
            'availability_status' => 'AVAILABLE',
            'eligibility_status' => $eligibilityStatus,
            'display_time' => $pricing['totals']['estimated_hhmm'],
            'display_route_hours' => $pricing['totals']['estimated_hours'],
            'final_billable_hours' => $pricing['totals']['estimated_hours'],
            'pricing' => $pricing,
            'pricing_breakdown' => $pricing['breakdown'],
            'estimated_total' => $pricing['breakdown']['total'],
            'total' => $pricing['breakdown']['total'],
            'total_amount' => $pricing['breakdown']['total'],
            'currency' => (string) config('quote_engine.currency', 'USD'),
            'customer_routes' => array_values(array_filter($operationalRoutes, fn (array $route): bool => ! ($route['positioning'] ?? false))),
            'ferry_routes' => array_values(array_filter($operationalRoutes, fn (array $route): bool => (bool) ($route['positioning'] ?? false))),
            'route_breakdowns' => $breakdowns,
        ];
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<int, array<string, mixed>>
     */
    private function normalizeLegs(array $request): array
    {
        $legs = $request['legs'] ?? [];

        if ($legs === [] && isset($request['origin'], $request['destination'], $request['departure_datetime'])) {
            $legs = [[
                'origin' => $request['origin'],
                'destination' => $request['destination'],
                'departure_datetime' => $request['departure_datetime'],
                'passengers' => $request['passengers'] ?? null,
            ]];
        }

        $normalized = [];

        foreach ($legs as $index => $leg) {
            $origin = $this->repository->resolveAirport($this->normalizeAirportInput($leg['origin'] ?? null));
            $destination = $this->repository->resolveAirport($this->normalizeAirportInput($leg['destination'] ?? null));
            $departure = (string) ($leg['departure_datetime'] ?? '');

            if (! $origin || ! $destination || $departure === '') {
                throw new InvalidArgumentException('AIRPORT_NOT_RESOLVED: leg '.($index + 1).' could not be resolved.');
            }

            if ($origin['id'] === $destination['id']) {
                throw new InvalidArgumentException('INVALID_ROUTE: origin and destination must be different.');
            }

            $normalized[] = [
                'leg_order' => $index + 1,
                'origin' => $origin,
                'destination' => $destination,
                'departure_datetime' => $departure,
                'passengers' => (int) ($leg['passengers'] ?? $request['passengers'] ?? 0),
                'positioning' => false,
            ];
        }

        if ($normalized === []) {
            throw new InvalidArgumentException('INVALID_ROUTE: at least one leg is required.');
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeAirportInput(mixed $airport): array
    {
        if (is_array($airport)) {
            return $airport;
        }

        return ['iata' => $airport, 'name' => $airport, 'city' => $airport];
    }

    /**
     * @param  array<int, array<string, mixed>>  $customerRoutes
     * @return array<int, array<string, mixed>>
     */
    private function buildOperationalRoutes(array $aircraft, array $customerRoutes): array
    {
        $base = $this->repository->airportByAircraftBase($aircraft);

        if (! $base) {
            return $customerRoutes;
        }

        $routes = $customerRoutes;
        $firstOrigin = $customerRoutes[0]['origin'];
        $lastDestination = $customerRoutes[array_key_last($customerRoutes)]['destination'];

        if ($base['id'] !== $firstOrigin['id']) {
            array_unshift($routes, $this->positioningRoute($base, $firstOrigin, 'repositioning'));
        }

        if ($lastDestination['id'] !== $base['id']) {
            $routes[] = $this->positioningRoute($lastDestination, $base, 'return_to_base');
        }

        return array_values($routes);
    }

    /**
     * @return array<string, mixed>
     */
    private function positioningRoute(array $origin, array $destination, string $type): array
    {
        return [
            'leg_order' => 0,
            'origin' => $origin,
            'destination' => $destination,
            'departure_datetime' => null,
            'passengers' => 1,
            'positioning' => true,
            'positioning_type' => $type,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $routeList
     * @return array<string, mixed>
     */
    private function calculateRoutePrice(array $aircraft, array $route, int $routeIndex, array $routeList): array
    {
        $distanceNm = $this->distanceNm($route['origin'], $route['destination']);
        $speed = $this->aircraftSpeed($aircraft);

        if ($speed <= 0) {
            throw new InvalidArgumentException('PRICING_FAILED: aircraft speed is required.');
        }

        $defaults = $this->pricingDefaults($aircraft);
        $baseMinutes = (int) ceil(($distanceNm / $speed) * 60);
        $estimatedMinutes = (int) ceil((($distanceNm / $speed) * 60) + $defaults['margin_minutes']);
        $hours = $estimatedMinutes / 60;
        $flightCostRaw = $hours * $this->aircraftHourlyRate($aircraft);
        $nights = $this->routeNights($route, $routeIndex, $routeList);
        $overnightCost = round($nights * $defaults['overnight_fee_usd'], 2);

        return [
            'ready' => true,
            'flight_cost' => round($flightCostRaw, 2),
            'flight_cost_raw' => $flightCostRaw,
            'overnight_cost' => $overnightCost,
            'operational_cost' => $defaults['airport_fees_usd'],
            'nights' => $nights,
            'air_time' => round($distanceNm / $speed, 4),
            'hours' => $hours,
            'margin_minutes' => $defaults['margin_minutes'],
            'base_minutes' => $baseMinutes,
            'estimated_minutes' => $estimatedMinutes,
            'base_hhmm' => $this->minutesToHhMm($baseMinutes),
            'estimated_hhmm' => $this->minutesToHhMm($estimatedMinutes),
            'miles' => round($distanceNm, 1),
            'positioning' => (bool) ($route['positioning'] ?? false),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $routes
     * @param  array<int, array<string, mixed>>  $breakdowns
     * @return array<string, mixed>
     */
    private function summarizePricing(array $aircraft, array $routes, array $breakdowns): array
    {
        $defaults = $this->pricingDefaults($aircraft);
        $customerFlightCost = 0.0;
        $ferryFlightCost = 0.0;
        $overnight = 0.0;
        $totalMiles = 0.0;
        $totalEstimatedMinutes = 0;

        foreach ($breakdowns as $index => $breakdown) {
            $totalMiles += (float) $breakdown['miles'];
            $totalEstimatedMinutes += (int) $breakdown['estimated_minutes'];

            if ($routes[$index]['positioning'] ?? false) {
                $ferryFlightCost += (float) $breakdown['flight_cost_raw'];
            } else {
                $customerFlightCost += (float) $breakdown['flight_cost_raw'];
                $overnight += (float) $breakdown['overnight_cost'];
            }
        }

        $flightCost = round($customerFlightCost + $ferryFlightCost, 2);
        $operationalExpenses = $defaults['airport_fees_usd'];
        $otherCharges = $defaults['other_charges_usd'];
        $subtotal = round($flightCost + $overnight + $operationalExpenses + $otherCharges, 2);
        $commercialMargin = $defaults['apply_commercial_margin']
            ? round($subtotal * ($defaults['commercial_margin_percent'] / 100), 2)
            : 0.0;
        $tax = 0.0;
        $total = round($subtotal + $commercialMargin + $tax, 2);

        return [
            'breakdown' => [
                'customer_flight_cost' => round($customerFlightCost, 2),
                'ferry_flight_cost' => round($ferryFlightCost, 2),
                'flight_cost' => $flightCost,
                'overnight' => round($overnight, 2),
                'airport_operational_expenses' => $operationalExpenses,
                'other_charges' => $otherCharges,
                'subtotal' => $subtotal,
                'commercial_margin' => $commercialMargin,
                'commercial_margin_rate' => $defaults['commercial_margin_percent'] / 100,
                'tax' => $tax,
                'iva' => $tax,
                'total' => $total,
            ],
            'totals' => [
                'miles' => round($totalMiles, 1),
                'estimated_minutes' => $totalEstimatedMinutes,
                'estimated_hours' => round($totalEstimatedMinutes / 60, 2),
                'estimated_hhmm' => $this->minutesToHhMm($totalEstimatedMinutes),
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $routes
     * @param  array<int, array<string, mixed>>  $breakdowns
     * @return array{start:string,end:string}
     */
    private function reservationBounds(array $routes, array $breakdowns): array
    {
        $customerRoute = collect($routes)->first(fn (array $route): bool => ! ($route['positioning'] ?? false));
        $firstCustomerStart = new DateTimeImmutable((string) $customerRoute['departure_datetime']);
        $cursor = null;
        $starts = [];
        $ends = [];

        foreach ($routes as $index => $route) {
            $duration = (int) $breakdowns[$index]['estimated_minutes'];

            if (($route['positioning_type'] ?? null) === 'repositioning' && ! $route['departure_datetime']) {
                $end = $firstCustomerStart;
                $start = $end->modify("-{$duration} minutes");
            } elseif (($route['positioning_type'] ?? null) === 'return_to_base' && ! $route['departure_datetime']) {
                $start = $cursor ?? $firstCustomerStart;
                $end = $start->modify("+{$duration} minutes");
            } else {
                $start = new DateTimeImmutable((string) $route['departure_datetime']);
                $end = $start->modify("+{$duration} minutes");
            }

            $starts[] = $start;
            $ends[] = $end;
            $cursor = $end;
        }

        sort($starts);
        sort($ends);

        return [
            'start' => $starts[0]->format('Y-m-d\TH:i:s'),
            'end' => $ends[array_key_last($ends)]->format('Y-m-d\TH:i:s'),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $legs
     */
    private function aircraftRangeAllows(array $aircraft, array $legs): bool
    {
        $range = (float) $this->firstFilled($aircraft, ['range_nm', 'rangeNm', 'range'], 0);

        if ($range <= 0) {
            return true;
        }

        foreach ($legs as $leg) {
            if ($this->distanceNm($leg['origin'], $leg['destination']) > $range) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, array<string, mixed>>  $legs
     */
    private function aircraftAirportPerformanceAllows(array $aircraft, array $legs): bool
    {
        $minimumRunwayM = (float) $this->firstFilled($aircraft, ['minimum_runway_m'], 0);
        $maxAirportElevationFt = (float) $this->firstFilled($aircraft, ['max_airport_elevation_ft'], 0);

        if ($minimumRunwayM <= 0 && $maxAirportElevationFt <= 0) {
            return true;
        }

        foreach ($legs as $leg) {
            foreach ([$leg['origin'], $leg['destination']] as $airport) {
                $runwayLengthM = (float) $this->firstFilled($airport, ['runway_length_m'], 0);
                $elevationFt = (float) $this->firstFilled($airport, ['elevation_ft'], 0);

                if ($minimumRunwayM > 0 && $runwayLengthM > 0 && $runwayLengthM < $minimumRunwayM) {
                    return false;
                }

                if ($maxAirportElevationFt > 0 && $elevationFt > $maxAirportElevationFt) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function pricingDefaults(array $aircraft): array
    {
        $typeRule = $this->operationalRule((string) $this->firstFilled($aircraft, ['aircraft_type', 'type', 'category'], ''));
        $override = $this->modelOverride((string) $this->firstFilled($aircraft, ['aircraft_name', 'name', 'model'], ''));
        $hourlyRate = $this->aircraftHourlyRate($aircraft);

        return [
            'margin_minutes' => (int) $this->firstFilled($aircraft, ['operational_margin_minutes', 'operationalMarginMinutes'], $override['margin_minutes'] ?? $typeRule['margin_minutes'] ?? 30),
            'overnight_fee_usd' => (float) $this->firstFilled($aircraft, ['overnight_fee_usd', 'overnightFeeUsd', 'crew_overnight_usd', 'crewOvernightUsd'], $override['overnight_fee_usd'] ?? $typeRule['overnight_fee_usd'] ?? round($hourlyRate / 2, 2)),
            'apply_commercial_margin' => (bool) $this->firstFilled($aircraft, ['apply_commercial_margin', 'applyCommercialMargin'], true),
            'commercial_margin_percent' => (float) $this->firstFilled($aircraft, ['commercial_margin_percent', 'commercialMarginPercent'], $override['commercial_margin_percent'] ?? $typeRule['commercial_margin_percent'] ?? ((float) config('quote_engine.commercial_margin_rate') * 100)),
            'airport_fees_usd' => (float) $this->firstFilled($aircraft, ['airport_fees_usd', 'airportFeesUsd', 'national_expenses_usd', 'international_expenses_usd'], $override['airport_fees_usd'] ?? 0),
            'other_charges_usd' => (float) $this->firstFilled($aircraft, ['other_charges_usd', 'otherChargesUsd'], config('quote_engine.other_charges_default')),
        ];
    }

    /**
     * @return array<string, int|float>
     */
    private function operationalRule(string $type): array
    {
        $normalized = mb_strtoupper($type);
        $rules = config('quote_engine.operational_rules', []);

        return match (true) {
            str_contains($normalized, 'HELICOP') => $rules['HELICOPTERO'],
            str_contains($normalized, 'MONOMOTOR') || str_contains($normalized, 'PISTON') => $rules['MONOMOTOR PISTON'],
            str_contains($normalized, 'TURBOH') => $rules['TURBOHELICE'],
            str_contains($normalized, 'LIGHT JET') || str_contains($normalized, 'JET LIGERO') => $rules['JET LIGERO (LIGHT JET)'],
            str_contains($normalized, 'MIDSIZE JET') || str_contains($normalized, 'MID JET') => $rules['MIDSIZE JET (MID JET)'],
            str_contains($normalized, 'SUPER MIDSIZE') => $rules['SUPER MIDSIZE JET'],
            str_contains($normalized, 'HEAVY') => $rules['HEAVY JET'],
            str_contains($normalized, 'REGIONAL') => $rules['REGIONAL JET'],
            default => ['margin_minutes' => 30, 'commercial_margin_percent' => 15, 'overnight_fee_usd' => 0],
        };
    }

    /**
     * @return array<string, int|float>
     */
    private function modelOverride(string $model): array
    {
        $normalized = mb_strtoupper($model);

        foreach (config('quote_engine.model_overrides', []) as $name => $override) {
            if (str_contains($normalized, mb_strtoupper((string) $name))) {
                return $override;
            }
        }

        return [];
    }

    private function preferredSort(array $left, array $right, string $preferredAircraftId): int
    {
        if ($preferredAircraftId === '') {
            return 0;
        }

        return (int) ((string) $right['aircraft_id'] === $preferredAircraftId) <=> (int) ((string) $left['aircraft_id'] === $preferredAircraftId);
    }

    private function distanceNm(array $origin, array $destination): float
    {
        $earthRadiusKm = 6371;
        $deltaLat = deg2rad((float) $destination['lat'] - (float) $origin['lat']);
        $deltaLng = deg2rad((float) $destination['lng'] - (float) $origin['lng']);
        $originLat = deg2rad((float) $origin['lat']);
        $destinationLat = deg2rad((float) $destination['lat']);
        $haversine = sin($deltaLat / 2) ** 2 + cos($originLat) * cos($destinationLat) * sin($deltaLng / 2) ** 2;

        return ($earthRadiusKm * 2 * atan2(sqrt($haversine), sqrt(1 - $haversine))) / 1.852;
    }

    private function routeNights(array $route, int $routeIndex, array $routeList): int
    {
        if (! ($route['departure_datetime'] ?? null) || ($route['positioning'] ?? false)) {
            return 0;
        }

        $nextRoute = $routeList[$routeIndex + 1] ?? null;

        if (! ($nextRoute['departure_datetime'] ?? null) || ($nextRoute['positioning'] ?? false)) {
            return 0;
        }

        $start = new DateTimeImmutable((string) $route['departure_datetime']);
        $nextStart = new DateTimeImmutable((string) $nextRoute['departure_datetime']);

        return max(0, (int) round(($nextStart->setTime(0, 0)->getTimestamp() - $start->setTime(0, 0)->getTimestamp()) / 86400));
    }

    private function minutesToHhMm(int|float $minutes): string
    {
        $rounded = (int) round($minutes);

        return sprintf('%02d:%02d', intdiv($rounded, 60), $rounded % 60);
    }

    private function aircraftCapacity(array $aircraft): int
    {
        return (int) $this->firstFilled($aircraft, ['capacity_passengers', 'capacity', 'passengers'], 0);
    }

    private function aircraftSpeed(array $aircraft): float
    {
        return (float) $this->firstFilled($aircraft, ['cruise_speed_knots', 'cruiseSpeedKnots', 'cruise_speed', 'speed_knots', 'speed'], 0);
    }

    private function aircraftHourlyRate(array $aircraft): float
    {
        return (float) $this->firstFilled($aircraft, ['rental_price_usd', 'rentalPriceUsd', 'precio_renta_usd'], 0);
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
}
