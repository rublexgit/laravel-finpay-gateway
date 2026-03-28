<?php

use Finpay\Http\Controllers\CallbackController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->group(function (): void {
    Route::post('finpay/callback', [CallbackController::class, 'handle'])->name('finpay.callback');
});
