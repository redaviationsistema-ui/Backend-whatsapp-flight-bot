<?php

namespace Tests\Feature;

use Tests\TestCase;

class QuoteParityControllerTest extends TestCase
{
    public function test_parity_check_reports_exact_matches_for_equivalent_results(): void
    {
        $result = $this->quoteResult();

        $response = $this->postJson('/api/v1/client/quotes/parity-check', [
            'quote_input_identifier' => 'one-way-tlc-cun',
            'quote_request' => ['passengers' => 4, 'legs' => []],
            'legacy_result' => $result,
            'backend_result' => $result,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('quote_input_identifier', 'one-way-tlc-cun')
            ->assertJsonPath('metrics.parity_checks', 18)
            ->assertJsonPath('metrics.exact_matches', 18)
            ->assertJsonPath('metrics.within_tolerance', 0)
            ->assertJsonPath('metrics.mismatches', 0)
            ->assertJsonPath('metrics.backend_errors', 0)
            ->assertJsonPath('metrics.parity_percentage', 100)
            ->assertJsonPath('matching.legacy_aircraft_ids', [101])
            ->assertJsonPath('matching.backend_aircraft_ids', [101])
            ->assertJsonCount(0, 'divergences');
    }

    public function test_parity_check_reports_component_divergences_with_tolerances(): void
    {
        $legacyResult = $this->quoteResult();
        $backendResult = $this->quoteResult([
            'pricing_breakdown' => [
                'customer_flight_cost' => 8614.00,
                'ferry_flight_cost' => 14693.33,
                'overnight' => 0,
                'airport_operational_expenses' => 600,
                'other_charges' => 0,
                'commercial_margin' => 3586,
                'commercial_margin_rate' => 0.15,
                'tax' => 0,
                'iva' => 0,
                'total' => 27493.34,
            ],
            'pricing' => [
                'totals' => [
                    'miles' => 2469.4,
                    'estimated_hours' => 6.21,
                    'estimated_minutes' => 373,
                ],
            ],
            'estimated_total' => 27493.34,
            'total' => 27493.34,
            'total_amount' => 27493.34,
        ]);

        $response = $this->postJson('/api/v1/client/quotes/parity-check', [
            'quote_input_identifier' => 'rounding-case',
            'quote_request' => ['passengers' => 4, 'legs' => []],
            'legacy_result' => $legacyResult,
            'backend_result' => $backendResult,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('metrics.parity_checks', 18)
            ->assertJsonPath('metrics.exact_matches', 13)
            ->assertJsonPath('metrics.within_tolerance', 1)
            ->assertJsonPath('metrics.mismatches', 4)
            ->assertJsonPath('divergences.0.quote_input_identifier', 'rounding-case')
            ->assertJsonPath('divergences.0.aircraft_id', 101)
            ->assertJsonPath('divergences.0.field', 'pricing_breakdown.customer_flight_cost')
            ->assertJsonPath('divergences.0.legacy_value', 8613.33)
            ->assertJsonPath('divergences.0.backend_value', 8614)
            ->assertJsonPath('divergences.0.absolute_difference', 0.6700000000000728)
            ->assertJsonPath('divergences.0.classification', 'requires_review');
    }

    public function test_parity_check_keeps_compare_flow_alive_when_backend_errors(): void
    {
        $response = $this->postJson('/api/v1/client/quotes/parity-check', [
            'quote_input_identifier' => 'bad-airport',
            'quote_request' => [
                'passengers' => 4,
                'legs' => [[
                    'origin' => ['iata' => 'XXX'],
                    'destination' => ['iata' => 'CUN'],
                    'departure_datetime' => '2026-10-15T14:30:00',
                ]],
            ],
            'legacy_result' => $this->quoteResult(),
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('metrics.backend_errors', 1)
            ->assertJsonPath('metrics.parity_checks', 0)
            ->assertJsonPath('divergences.0.field', 'backend_error')
            ->assertJsonPath('divergences.0.classification', 'backend_error');
    }

    /**
     * @param  array<string, mixed>  $optionOverrides
     * @return array<string, mixed>
     */
    private function quoteResult(array $optionOverrides = []): array
    {
        $option = array_replace_recursive([
            'aircraft_id' => 101,
            'aircraft_name' => 'Citation CJ3',
            'estimated_total' => 27492.67,
            'total' => 27492.67,
            'total_amount' => 27492.67,
            'customer_routes' => [['leg_order' => 1]],
            'ferry_routes' => [['positioning_type' => 'repositioning'], ['positioning_type' => 'return_to_base']],
            'pricing_breakdown' => [
                'customer_flight_cost' => 8613.33,
                'ferry_flight_cost' => 14693.33,
                'overnight' => 0,
                'airport_operational_expenses' => 600,
                'other_charges' => 0,
                'commercial_margin' => 3586,
                'commercial_margin_rate' => 0.15,
                'tax' => 0,
                'iva' => 0,
                'total' => 27492.67,
            ],
            'pricing' => [
                'totals' => [
                    'miles' => 2469.4,
                    'estimated_hours' => 6.22,
                    'estimated_minutes' => 373,
                ],
            ],
        ], $optionOverrides);

        return [
            'status' => 'ok',
            'currency' => 'USD',
            'estimated_min' => $option['estimated_total'],
            'estimated_max' => $option['estimated_total'],
            'options' => [$option],
        ];
    }
}
