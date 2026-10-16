<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\TokenController;
use Illuminate\Support\Facades\Route;

// The only public route left. Issuing a token requires an account that already
// exists, so the door is now closed behind it — see the registration note below.
Route::post('/auth/token', [TokenController::class, 'store'])->middleware('throttle:6,1');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::delete('/auth/token', [TokenController::class, 'destroy']);

    // Creating an account, which is an administrator's job.
    //
    // This was public until the catalogue became readable. An open registration
    // endpoint was tolerable while every desk route answered 403 to the account it
    // produced; afterwards it was a way for any stranger to sign up and read the
    // product list, every customer's credit limit, and the reference lists.
    //
    // Closed by moving rather than by deleting, so the endpoint still documents
    // itself and still works for an administrator holding a token. The cost is
    // honest and worth stating: Swagger UI can no longer bootstrap itself from a
    // cold start, because the token it would use to register is the thing
    // registration now requires. Exercising the API needs an account first.
    Route::middleware('admin')->group(function (): void {
        Route::post('/account', [AccountController::class, 'store'])->name('api.account.store');
    });

    // Self-service: these always act on the authenticated account, so there is
    // no route through which one user can read or rewrite another's. Open to
    // every account, administrator or not — an ordinary user still needs to read
    // their own record and change their own password.
    Route::get('/account', [AccountController::class, 'show'])->name('api.account.show');
    Route::patch('/account', [AccountController::class, 'update'])->name('api.account.update');
    Route::delete('/account', [AccountController::class, 'destroy'])->name('api.account.destroy');

    // The catalogue and the two directories are all part of the desk, so they all
    // carry the same administrator rule the web routes do. Declared on the group so
    // a token cannot read the customer list while the browser cannot.
    Route::middleware('admin')->group(function (): void {
        // Declared one by one rather than via apiResource: updates are partial, so a
        // PUT route would promise full-replacement semantics the controller does not honour.
        Route::get('/products', [ProductController::class, 'index'])->name('api.products.index');
        Route::post('/products', [ProductController::class, 'store'])->name('api.products.store');
        Route::get('/products/{product}', [ProductController::class, 'show'])->name('api.products.show');
        Route::patch('/products/{product}', [ProductController::class, 'update'])->name('api.products.update');
        Route::delete('/products/{product}', [ProductController::class, 'destroy'])->name('api.products.destroy');

    });
});
