<?php

return [
    'timezone' => env('WHATSAPP_TIMEZONE', 'America/Mexico_City'),
    'lock_store' => env('WHATSAPP_LOCK_STORE', 'database'),
    'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
    'business_account_id' => env('WHATSAPP_BUSINESS_ACCOUNT_ID'),
    'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
    'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
    'api_version' => env('WHATSAPP_API_VERSION', env('WHATSAPP_GRAPH_VERSION', 'v26.0')),
    'app_secret' => env('WHATSAPP_APP_SECRET', env('META_APP_SECRET')),
];
