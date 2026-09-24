<?php

use App\Http\Controllers\Flights\QuoteParityController;
use App\Http\Controllers\Flights\QuotePreviewController;
use App\Http\Controllers\WhatsApp\WhatsAppAdminConversationController;
use App\Http\Controllers\WhatsApp\WhatsAppAdminDashboardController;
use App\Http\Controllers\WhatsApp\WhatsAppAdminFlightRequestController;
use App\Http\Controllers\WhatsApp\WhatsAppAdminRequestController;
use App\Http\Controllers\WhatsApp\WhatsAppAdminSessionController;
use App\Http\Controllers\WhatsApp\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/whatsapp/webhook', [WhatsAppWebhookController::class, 'verify'])
    ->name('whatsapp.webhook.verify');

Route::post('/whatsapp/webhook', [WhatsAppWebhookController::class, 'receive'])
    ->middleware('throttle:whatsapp-webhook')
    ->name('whatsapp.webhook.receive');

Route::get('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify'])
    ->name('whatsapp.webhook.verify.meta');

Route::post('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'receive'])
    ->middleware('throttle:whatsapp-webhook')
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

Route::prefix('admin/whatsapp/conversations')->name('admin.whatsapp.conversations.')->middleware(['web', 'auth:web', 'whatsapp.admin', 'throttle:whatsapp-admin'])->group(function (): void {
    Route::get('/', [WhatsAppAdminConversationController::class, 'index'])->name('index');
    Route::get('/{conversation}', [WhatsAppAdminConversationController::class, 'show'])->name('show');
    Route::get('/{conversation}/messages', [WhatsAppAdminConversationController::class, 'messages'])->name('messages.index');
    Route::post('/{conversation}/messages', [WhatsAppAdminConversationController::class, 'send'])->name('messages.store');
    Route::post('/{conversation}/takeover', [WhatsAppAdminConversationController::class, 'takeover'])->name('takeover');
    Route::post('/{conversation}/transfer-to-human', [WhatsAppAdminConversationController::class, 'takeover'])->name('transfer-to-human');
    Route::post('/{conversation}/return-to-bot', [WhatsAppAdminConversationController::class, 'returnToBot'])->name('return-to-bot');
});

Route::prefix('admin/whatsapp')->name('admin.whatsapp.')->middleware(['web', 'auth:web', 'whatsapp.admin', 'throttle:whatsapp-admin'])->group(function (): void {
    Route::get('/dashboard', [WhatsAppAdminDashboardController::class, 'dashboard'])->name('dashboard');
    Route::get('/history', [WhatsAppAdminDashboardController::class, 'history'])->name('history');

    Route::get('/parts', [WhatsAppAdminRequestController::class, 'parts'])->name('parts.index');
    Route::get('/parts/{partRequest}', [WhatsAppAdminRequestController::class, 'showPart'])->name('parts.show');
    Route::patch('/parts/{partRequest}/status', [WhatsAppAdminRequestController::class, 'updatePartStatus'])->name('parts.status');

    Route::get('/engines', [WhatsAppAdminRequestController::class, 'engines'])->name('engines.index');
    Route::get('/engines/{engineRequest}', [WhatsAppAdminRequestController::class, 'showEngine'])->name('engines.show');
    Route::patch('/engines/{engineRequest}/status', [WhatsAppAdminRequestController::class, 'updateEngineStatus'])->name('engines.status');

    Route::get('/support', [WhatsAppAdminRequestController::class, 'support'])->name('support.index');
    Route::get('/support/{supportRequest}', [WhatsAppAdminRequestController::class, 'showSupport'])->name('support.show');
    Route::patch('/support/{supportRequest}/status', [WhatsAppAdminRequestController::class, 'updateSupportStatus'])->name('support.status');

    Route::get('/advisor-requests', [WhatsAppAdminRequestController::class, 'advisor'])->name('advisor-requests.index');
    Route::get('/advisor-requests/{advisorRequest}', [WhatsAppAdminRequestController::class, 'showAdvisor'])->name('advisor-requests.show');
    Route::patch('/advisor-requests/{advisorRequest}/status', [WhatsAppAdminRequestController::class, 'updateAdvisorStatus'])->name('advisor-requests.status');
});

Route::prefix('admin/whatsapp/flight-requests')->name('admin.whatsapp.flight-requests.')->middleware(['web', 'auth:web', 'whatsapp.admin', 'throttle:whatsapp-admin'])->group(function (): void {
    Route::get('/', [WhatsAppAdminFlightRequestController::class, 'index'])->name('index');
    Route::get('/{flightRequest}', [WhatsAppAdminFlightRequestController::class, 'show'])->name('show');
});
