<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'success' => true,
        'service' => 'Sky Group WhatsApp API',
        'status' => 'online',
    ]);
});
