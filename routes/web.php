<?php

use App\Http\Controllers\GenerationController;
use App\Http\Controllers\PixelforgeWebhookController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::post('/webhooks/pixelforge', PixelforgeWebhookController::class)
    ->name('webhooks.pixelforge');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [GenerationController::class, 'index'])->name('dashboard');
    Route::post('/generations/estimate', [GenerationController::class, 'estimate'])
        ->middleware('throttle:30,1')
        ->name('generations.estimate');
    Route::post('/generations', [GenerationController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('generations.store');
    Route::get('/generations/{id}/status', [GenerationController::class, 'status'])
        ->middleware('throttle:60,1')
        ->name('generations.status');
});

require __DIR__.'/settings.php';
