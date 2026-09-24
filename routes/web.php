<?php

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'ready'])->name('health');
Route::get('/health/live', [HealthController::class, 'live'])->name('health.live');
Route::get('/health/ready', [HealthController::class, 'ready'])->name('health.ready');
Route::get('/', [HealthController::class, 'status'])->name('status');
