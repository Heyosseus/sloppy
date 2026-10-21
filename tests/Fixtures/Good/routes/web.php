<?php

declare(strict_types=1);

use App\Http\Controllers\CheckoutController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('welcome'));

Route::middleware('auth')->group(function () {
    Route::get('/orders', [CheckoutController::class, 'index'])->name('orders.index');
    Route::get('/orders/{order}', [CheckoutController::class, 'show'])->name('orders.show');
    Route::post('/orders', [CheckoutController::class, 'store'])->name('orders.store');
    Route::delete('/orders/{order}', [CheckoutController::class, 'destroy'])->name('orders.destroy');
});

Route::controller(CheckoutController::class)->prefix('checkout')->group(function () {
    Route::get('/', 'index');
    Route::get('/{order}', 'show');
});
