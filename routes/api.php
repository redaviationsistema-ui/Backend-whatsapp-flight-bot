<?php

use App\Http\Controllers\Flights\QuoteParityController;
use App\Http\Controllers\Flights\QuotePreviewController;
use App\Http\Controllers\WhatsApp\WhatsAppAdminConversationController;
use App\Http\Controllers\WhatsApp\WhatsAppAdminFlightRequestController;
use App\Http\Controllers\WhatsApp\WhatsAppAdminSessionController;
use App\Http\Controllers\WhatsApp\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/whatsapp/webhook', [WhatsAppWebhookController::class, 'verify'])
    ->name('whatsapp.webhook.verify');

Route::post('/whatsapp/webhook', [WhatsAppWebhookController::class, 'receive'])
    ->name('whatsapp.webhook.receive');

Route::get('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify'])
    ->name('whatsapp.webhook.verify.meta');

Route::post('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'receive'])
    ->name('whatsapp.webhook.receive.meta');

Route::post('/v1/client/quotes/preview', QuotePreviewController::class)
    ->middleware('throttle:60,1')
    ->name('client.quotes.preview');

Route::post('/v1/client/quotes/parity-check', QuoteParityController::class)
    ->middleware('throttle:60,1')
    ->name('client.quotes.parity-check');

Route::prefix('admin')->name('admin.')->middleware('web')->group(function (): void {
    Route::get('csrf', [WhatsAppAdminSessionController::class, 'csrf'])->name('csrf');
    Route::post('login', [WhatsAppAdminSessionController::class, 'store'])->middleware('throttle:admin-login')->name('login');
    Route::post('logout', [WhatsAppAdminSessionController::class, 'destroy'])->middleware('auth:web')->name('logout');

});

Route::prefix('admin/whatsapp/conversations')->name('admin.whatsapp.conversations.')->middleware('throttle:60,1')->group(function (): void {
    Route::get('/', [WhatsAppAdminConversationController::class, 'index'])->name('index');
    Route::get('/{conversation}', [WhatsAppAdminConversationController::class, 'show'])->name('show');
    Route::get('/{conversation}/messages', [WhatsAppAdminConversationController::class, 'messages'])->name('messages.index');
    Route::post('/{conversation}/messages', [WhatsAppAdminConversationController::class, 'send'])->name('messages.store');
    Route::post('/{conversation}/takeover', [WhatsAppAdminConversationController::class, 'takeover'])->name('takeover');
    Route::post('/{conversation}/return-to-bot', [WhatsAppAdminConversationController::class, 'returnToBot'])->name('return-to-bot');
});

Route::prefix('admin/whatsapp/flight-requests')->name('admin.whatsapp.flight-requests.')->middleware('throttle:60,1')->group(function (): void {
    Route::get('/', [WhatsAppAdminFlightRequestController::class, 'index'])->name('index');
    Route::get('/{flightRequest}', [WhatsAppAdminFlightRequestController::class, 'show'])->name('show');
});
