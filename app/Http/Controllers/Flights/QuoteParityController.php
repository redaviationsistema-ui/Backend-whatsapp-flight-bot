<?php

namespace App\Http\Controllers\Flights;

use App\Http\Controllers\Controller;
use App\Services\Quotes\QuoteDatabaseNotConfiguredException;
use App\Services\Quotes\QuoteEngine;
use App\Services\Quotes\QuoteParityComparator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

class QuoteParityController extends Controller
{
    public function __invoke(Request $request, QuoteEngine $quoteEngine, QuoteParityComparator $comparator): JsonResponse
    {
        $validated = $request->validate([
            'quote_input_identifier' => ['nullable', 'string', 'max:120'],
            'quote_request' => ['required', 'array'],
            'legacy_result' => ['required', 'array'],
            'backend_result' => ['nullable', 'array'],
        ]);

        try {
            $backendResult = $validated['backend_result'] ?? $quoteEngine->preview($validated['quote_request']);
            $comparison = $comparator->compare(
                $validated['legacy_result'],
                $backendResult,
                $validated['quote_input_identifier'] ?? null,
            );
        } catch (InvalidArgumentException|QuoteDatabaseNotConfiguredException $exception) {
            $comparison = [
                'status' => 'error',
                'mode' => (string) config('quote_engine.web_mode', 'compare'),
                'quote_input_identifier' => $validated['quote_input_identifier'] ?? null,
                'metrics' => [
                    'parity_checks' => 0,
                    'exact_matches' => 0,
                    'within_tolerance' => 0,
                    'mismatches' => 0,
                    'backend_errors' => 1,
                    'parity_percentage' => 0.0,
                ],
                'divergences' => [[
                    'quote_input_identifier' => $validated['quote_input_identifier'] ?? null,
                    'aircraft_id' => null,
                    'field' => 'backend_error',
                    'legacy_value' => null,
                    'backend_value' => $exception->getMessage(),
                    'absolute_difference' => null,
                    'percentage_difference' => null,
                    'classification' => 'backend_error',
                ]],
            ];
        } catch (Throwable $exception) {
            report($exception);

            $comparison = [
                'status' => 'error',
                'mode' => (string) config('quote_engine.web_mode', 'compare'),
                'quote_input_identifier' => $validated['quote_input_identifier'] ?? null,
                'metrics' => [
                    'parity_checks' => 0,
                    'exact_matches' => 0,
                    'within_tolerance' => 0,
                    'mismatches' => 0,
                    'backend_errors' => 1,
                    'parity_percentage' => 0.0,
                ],
                'divergences' => [[
                    'quote_input_identifier' => $validated['quote_input_identifier'] ?? null,
                    'aircraft_id' => null,
                    'field' => 'backend_error',
                    'legacy_value' => null,
                    'backend_value' => 'SERVICE_ERROR',
                    'absolute_difference' => null,
                    'percentage_difference' => null,
                    'classification' => 'backend_error',
                ]],
            ];
        }

        return response()->json($comparison);
    }
}
