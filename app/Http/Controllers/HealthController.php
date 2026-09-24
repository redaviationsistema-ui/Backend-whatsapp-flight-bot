<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    public function status(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'service' => 'Sky Group WhatsApp API',
            'status' => 'online',
        ]);
    }

    public function live(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'app' => 'ok',
        ]);
    }

    public function ready(): JsonResponse
    {
        $database = $this->checkConnection(config('database.default'));
        $quoteDatabase = $this->checkQuoteDatabase();

        $status = $database === 'ok' && $quoteDatabase !== 'down' ? 'ok' : 'down';

        if ($database === 'ok' && $quoteDatabase === 'degraded') {
            $status = 'degraded';
        }

        return response()->json([
            'status' => $status,
            'database' => $database,
            'quote_database' => $quoteDatabase,
        ], $status === 'down' ? 503 : 200);
    }

    private function checkConnection(?string $connection): string
    {
        try {
            DB::connection($connection)->select('select 1');

            return 'ok';
        } catch (Throwable) {
            return 'down';
        }
    }

    private function checkQuoteDatabase(): string
    {
        $config = (array) config('database.connections.quote_db', []);

        if (($config['url'] ?? '') === '' && (($config['database'] ?? '') === '' || ($config['username'] ?? '') === '')) {
            return 'degraded';
        }

        return $this->checkConnection('quote_db');
    }
}
