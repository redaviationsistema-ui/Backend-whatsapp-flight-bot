<?php

namespace App\Services\Quotes;

class QuoteParityComparator
{
    /**
     * @param  array<string, mixed>  $legacyResult
     * @param  array<string, mixed>  $backendResult
     * @return array<string, mixed>
     */
    public function compare(array $legacyResult, array $backendResult, ?string $inputIdentifier = null): array
    {
        $legacyOptions = $this->optionsByAircraft($legacyResult['options'] ?? []);
        $backendOptions = $this->optionsByAircraft($backendResult['options'] ?? []);
        $legacyIds = array_keys($legacyOptions);
        $backendIds = array_keys($backendOptions);
        $divergences = [];
        $checks = 0;
        $exactMatches = 0;
        $withinTolerance = 0;

        foreach (array_diff($legacyIds, $backendIds) as $aircraftId) {
            $divergences[] = $this->divergence($inputIdentifier, (int) $aircraftId, 'matching.aircraft_missing_in_backend', true, false);
        }

        foreach (array_diff($backendIds, $legacyIds) as $aircraftId) {
            $divergences[] = $this->divergence($inputIdentifier, (int) $aircraftId, 'matching.aircraft_extra_in_backend', false, true);
        }

        foreach (array_intersect($legacyIds, $backendIds) as $aircraftId) {
            [$optionChecks, $optionExact, $optionWithin, $optionDivergences] = $this->compareOption(
                $legacyOptions[$aircraftId],
                $backendOptions[$aircraftId],
                $inputIdentifier,
                (int) $aircraftId,
            );

            $checks += $optionChecks;
            $exactMatches += $optionExact;
            $withinTolerance += $optionWithin;
            $divergences = [...$divergences, ...$optionDivergences];
        }

        [$rangeChecks, $rangeExact, $rangeWithin, $rangeDivergences] = $this->compareRange($legacyResult, $backendResult, $inputIdentifier);
        $checks += $rangeChecks;
        $exactMatches += $rangeExact;
        $withinTolerance += $rangeWithin;
        $divergences = [...$divergences, ...$rangeDivergences];

        $mismatches = count($divergences);
        $matches = $exactMatches + $withinTolerance;

        return [
            'status' => 'ok',
            'mode' => (string) config('quote_engine.web_mode', 'compare'),
            'quote_input_identifier' => $inputIdentifier,
            'matching' => [
                'legacy_aircraft_ids' => array_map('intval', $legacyIds),
                'backend_aircraft_ids' => array_map('intval', $backendIds),
                'matched_aircraft_ids' => array_map('intval', array_values(array_intersect($legacyIds, $backendIds))),
            ],
            'metrics' => [
                'parity_checks' => $checks,
                'exact_matches' => $exactMatches,
                'within_tolerance' => $withinTolerance,
                'mismatches' => $mismatches,
                'backend_errors' => (int) (($backendResult['status'] ?? 'ok') === 'error'),
                'parity_percentage' => $checks === 0 ? 0.0 : round(($matches / $checks) * 100, 2),
            ],
            'divergences' => $divergences,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $options
     * @return array<int|string, array<string, mixed>>
     */
    private function optionsByAircraft(array $options): array
    {
        $mapped = [];

        foreach ($options as $option) {
            if (! is_array($option) || ! isset($option['aircraft_id'])) {
                continue;
            }

            $mapped[(string) $option['aircraft_id']] = $option;
        }

        ksort($mapped);

        return $mapped;
    }

    /**
     * @return array{0:int,1:int,2:int,3:array<int, array<string, mixed>>}
     */
    private function compareOption(array $legacy, array $backend, ?string $inputIdentifier, int $aircraftId): array
    {
        $fields = [
            ['aircraft_id', 'aircraft_id', 'integer'],
            ['customer_routes.count', 'customer_routes.count', 'integer'],
            ['ferry_routes.count', 'ferry_routes.count', 'integer'],
            ['pricing_breakdown.customer_flight_cost', 'pricing_breakdown.customer_flight_cost', 'money'],
            ['pricing_breakdown.ferry_flight_cost', 'pricing_breakdown.ferry_flight_cost', 'money'],
            ['pricing_breakdown.overnight', 'pricing_breakdown.overnight', 'money'],
            ['pricing_breakdown.airport_operational_expenses', 'pricing_breakdown.airport_operational_expenses', 'money'],
            ['pricing_breakdown.other_charges', 'pricing_breakdown.other_charges', 'money'],
            ['pricing_breakdown.commercial_margin', 'pricing_breakdown.commercial_margin', 'money'],
            ['pricing_breakdown.commercial_margin_rate', 'pricing_breakdown.commercial_margin_rate', 'percentage'],
            ['pricing_breakdown.tax', 'pricing_breakdown.tax', 'money'],
            ['pricing_breakdown.iva', 'pricing_breakdown.iva', 'money'],
            ['pricing_breakdown.total', 'pricing_breakdown.total', 'money'],
            ['pricing.totals.miles', 'pricing.totals.miles', 'distance_nm'],
            ['pricing.totals.estimated_hours', 'pricing.totals.estimated_hours', 'flight_time_hours'],
            ['pricing.totals.estimated_minutes', 'pricing.totals.estimated_minutes', 'integer'],
        ];

        return $this->compareFields($legacy, $backend, $fields, $inputIdentifier, $aircraftId);
    }

    /**
     * @return array{0:int,1:int,2:int,3:array<int, array<string, mixed>>}
     */
    private function compareRange(array $legacy, array $backend, ?string $inputIdentifier): array
    {
        return $this->compareFields($legacy, $backend, [
            ['estimated_min', 'estimated_min', 'money'],
            ['estimated_max', 'estimated_max', 'money'],
        ], $inputIdentifier, null);
    }

    /**
     * @param  array<int, array{0:string,1:string,2:string}>  $fields
     * @return array{0:int,1:int,2:int,3:array<int, array<string, mixed>>}
     */
    private function compareFields(array $legacy, array $backend, array $fields, ?string $inputIdentifier, ?int $aircraftId): array
    {
        $checks = 0;
        $exactMatches = 0;
        $withinTolerance = 0;
        $divergences = [];

        foreach ($fields as [$legacyPath, $backendPath, $toleranceKey]) {
            $legacyValue = $this->value($legacy, $legacyPath);
            $backendValue = $this->value($backend, $backendPath);
            $checks++;

            if ($legacyValue === $backendValue) {
                $exactMatches++;

                continue;
            }

            $difference = $this->absoluteDifference($legacyValue, $backendValue);

            if ($difference !== null && $difference <= $this->tolerance($toleranceKey)) {
                $withinTolerance++;

                continue;
            }

            $divergences[] = $this->divergence(
                $inputIdentifier,
                $aircraftId,
                $legacyPath,
                $legacyValue,
                $backendValue,
                $difference,
            );
        }

        return [$checks, $exactMatches, $withinTolerance, $divergences];
    }

    private function value(array $source, string $path): mixed
    {
        if (str_ends_with($path, '.count')) {
            $value = data_get($source, substr($path, 0, -6), []);

            return is_countable($value) ? count($value) : 0;
        }

        return data_get($source, $path);
    }

    private function tolerance(string $key): float
    {
        return (float) config("quote_engine.parity_tolerances.{$key}", 0);
    }

    private function absoluteDifference(mixed $legacyValue, mixed $backendValue): ?float
    {
        if (! is_numeric($legacyValue) || ! is_numeric($backendValue)) {
            return null;
        }

        return abs((float) $legacyValue - (float) $backendValue);
    }

    /**
     * @return array<string, mixed>
     */
    private function divergence(?string $inputIdentifier, ?int $aircraftId, string $field, mixed $legacyValue, mixed $backendValue, ?float $difference = null): array
    {
        $percentageDifference = null;

        if ($difference !== null && is_numeric($legacyValue) && (float) $legacyValue !== 0.0) {
            $percentageDifference = round(($difference / abs((float) $legacyValue)) * 100, 4);
        }

        return [
            'quote_input_identifier' => $inputIdentifier,
            'aircraft_id' => $aircraftId,
            'field' => $field,
            'legacy_value' => $legacyValue,
            'backend_value' => $backendValue,
            'absolute_difference' => $difference,
            'percentage_difference' => $percentageDifference,
            'classification' => $this->classify($field, $difference),
        ];
    }

    private function classify(string $field, ?float $difference): string
    {
        if (str_starts_with($field, 'matching.')) {
            return 'matching_mismatch';
        }

        if ($difference !== null && $difference > 0) {
            return 'requires_review';
        }

        return 'data_mismatch';
    }
}
