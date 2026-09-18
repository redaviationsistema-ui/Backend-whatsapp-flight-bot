<?php

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
