<?php

return [
    'currency' => env('QUOTE_ENGINE_CURRENCY', 'USD'),
    'web_mode' => env('QUOTE_ENGINE_WEB_MODE', 'compare'),

    'parity_tolerances' => [
        'distance_nm' => 0.2,
        'flight_time_hours' => 0.02,
        'money' => 0.01,
        'percentage' => 0.0001,
        'integer' => 0,
    ],

    'tables' => [
        'national_airports' => env('QUOTE_ENGINE_NATIONAL_AIRPORTS_TABLE', 'aeropuertos_mexico'),
        'international_airports' => env('QUOTE_ENGINE_INTERNATIONAL_AIRPORTS_TABLE', 'airports_geo'),
        'aircraft' => env('QUOTE_ENGINE_AIRCRAFT_TABLE', 'aircraft_fleet'),
        'reservations' => env('QUOTE_ENGINE_RESERVATIONS_TABLE', 'reservations'),
        'blocked_dates' => env('QUOTE_ENGINE_BLOCKED_DATES_TABLE', 'blocked_dates'),
        'aircraft_airport_eligibilities' => env('QUOTE_ENGINE_AIRCRAFT_AIRPORT_ELIGIBILITIES_TABLE', 'aircraft_airport_eligibilities'),
    ],

    'commercial_margin_rate' => 0.15,
    'other_charges_default' => 0,

    'distance_limits_nm' => [
        'near' => 150,
        'regional' => 350,
    ],

    'operational_rules' => [
        'HELICOPTERO' => ['margin_minutes' => 15, 'commercial_margin_percent' => 15, 'overnight_fee_usd' => 350],
        'MONOMOTOR PISTON' => ['margin_minutes' => 15, 'commercial_margin_percent' => 15, 'overnight_fee_usd' => 250],
        'TURBOHELICE' => ['margin_minutes' => 20, 'commercial_margin_percent' => 15, 'overnight_fee_usd' => 450],
        'JET LIGERO (LIGHT JET)' => ['margin_minutes' => 30, 'commercial_margin_percent' => 15, 'overnight_fee_usd' => 650],
        'MIDSIZE JET (MID JET)' => ['margin_minutes' => 30, 'commercial_margin_percent' => 15, 'overnight_fee_usd' => 850],
        'SUPER MIDSIZE JET' => ['margin_minutes' => 35, 'commercial_margin_percent' => 15, 'overnight_fee_usd' => 1000],
        'HEAVY JET' => ['margin_minutes' => 40, 'commercial_margin_percent' => 15, 'overnight_fee_usd' => 1200],
        'REGIONAL JET' => ['margin_minutes' => 40, 'commercial_margin_percent' => 15, 'overnight_fee_usd' => 950],
    ],

    'model_overrides' => [
        'LEAR JET 31' => [
            'margin_minutes' => 15,
            'airport_fees_usd' => 500,
            'overnight_fee_usd' => 0,
        ],
    ],
];
