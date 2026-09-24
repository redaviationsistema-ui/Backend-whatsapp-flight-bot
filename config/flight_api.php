<?php

return [
    'mode' => env('FLIGHT_API_MODE', 'local'),
    'base_url' => env('FLIGHT_API_BASE_URL'),
    'token' => env('FLIGHT_API_TOKEN'),
    'timeout' => (int) env('FLIGHT_API_TIMEOUT', 20),
    'connect_timeout' => (int) env('FLIGHT_API_CONNECT_TIMEOUT', 5),
    'retry_times' => (int) env('FLIGHT_API_RETRY_TIMES', 2),
    'retry_sleep_ms' => (int) env('FLIGHT_API_RETRY_SLEEP_MS', 250),
];
