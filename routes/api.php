<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\TokenController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/token', [TokenController::class, 'store'])->middleware('throttle:6,1');
Route::post('/account', [AccountController::class, 'store'])->middleware('throttle:6,1');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::delete('/auth/token', [TokenController::class, 'destroy']);

    // Self-service: these always act on the authenticated account, so there is
    // no route through which one user can read or rewrite another's.
    Route::get('/account', [AccountController::class, 'show'])->name('api.account.show');
    Route::patch('/account', [AccountController::class, 'update'])->name('api.account.update');
    Route::delete('/account', [AccountController::class, 'destroy'])->name('api.account.destroy');

    // Declared one by one rather than via apiResource: updates are partial, so a
    // PUT route would promise full-replacement semantics the controller does not honour.
    Route::get('/products', [ProductController::class, 'index'])->name('api.products.index');
    Route::post('/products', [ProductController::class, 'store'])->name('api.products.store');
    Route::get('/products/{product}', [ProductController::class, 'show'])->name('api.products.show');
    Route::patch('/products/{product}', [ProductController::class, 'update'])->name('api.products.update');
    Route::delete('/products/{product}', [ProductController::class, 'destroy'])->name('api.products.destroy');
});
