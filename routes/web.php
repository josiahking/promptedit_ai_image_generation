<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PixelforgeWebhookController;

Route::inertia('/', 'Welcome')->name('home');

Route::post('/webhooks/pixelforge', PixelforgeWebhookController::class)
    ->name('webhooks.pixelforge');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
