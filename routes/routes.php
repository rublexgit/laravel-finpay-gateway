<?php

use Finpay\Http\Controllers\CallbackController;
use Illuminate\Support\Facades\Route;

Route::post('finpay/callback', [CallbackController::class, 'handle'])->name('finpay.callback');
