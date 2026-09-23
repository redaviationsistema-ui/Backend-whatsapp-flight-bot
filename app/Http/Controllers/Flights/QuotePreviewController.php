<?php

namespace App\Http\Controllers\Flights;

use App\Http\Controllers\Controller;
use App\Services\Quotes\QuoteDatabaseNotConfiguredException;
use App\Services\Quotes\QuoteEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class QuotePreviewController extends Controller
{
    public function __invoke(Request $request, QuoteEngine $quoteEngine): JsonResponse
    {
        $validated = $request->validate([
            'passengers' => ['required', 'integer', 'min:1'],
            'trip_type' => ['nullable', 'string'],
            'origin' => ['nullable'],
            'destination' => ['nullable'],
            'departure_datetime' => ['nullable', 'date'],
            'legs' => ['nullable', 'array'],
            'legs.*.origin' => ['required_with:legs'],
            'legs.*.destination' => ['required_with:legs'],
            'legs.*.departure_datetime' => ['required_with:legs', 'date'],
            'legs.*.passengers' => ['nullable', 'integer', 'min:1'],
            'aircraft_preference_id' => ['nullable', 'integer'],
            'allow_alternate_airports' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        try {
            return response()->json($quoteEngine->preview($validated));
        } catch (QuoteDatabaseNotConfiguredException) {
            return response()->json([
                'status' => 'error',
                'code' => 'QUOTE_DATABASE_NOT_CONFIGURED',
                'message' => 'Quote database connection is not configured.',
                'options' => [],
            ], 503);
        } catch (InvalidArgumentException $exception) {
            [$code, $message] = $this->splitBusinessError($exception->getMessage());

            return response()->json([
                'status' => 'error',
                'code' => $code,
                'message' => $message,
                'options' => [],
            ], 422);
        }
    }

    /**
     * @return array{0:string,1:string}
     */
    private function splitBusinessError(string $message): array
    {
        if (! str_contains($message, ':')) {
            return ['SERVICE_ERROR', $message];
        }

        [$code, $detail] = explode(':', $message, 2);

        return [trim($code), trim($detail)];
    }
}
