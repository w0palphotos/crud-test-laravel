<?php

use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\TokenController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/token', [TokenController::class, 'store'])->middleware('throttle:6,1');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::delete('/auth/token', [TokenController::class, 'destroy']);

    // Declared one by one rather than via apiResource: updates are partial, so a
    // PUT route would promise full-replacement semantics the controller does not honour.
    Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    Route::post('/products', [ProductController::class, 'store'])->name('products.store');
    Route::get('/products/{product}', [ProductController::class, 'show'])->name('products.show');
    Route::patch('/products/{product}', [ProductController::class, 'update'])->name('products.update');
    Route::delete('/products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');
});
