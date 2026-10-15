<?php

use App\Http\Controllers\Web\AccountController;
use App\Http\Controllers\Web\AssistantController;
use App\Http\Controllers\Web\CustomerController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\DirectoryController;
use App\Http\Controllers\Web\LoginController;
use App\Http\Controllers\Web\ProductController;
use App\Http\Controllers\Web\RegisterController;
use App\Http\Controllers\Web\SaleController;
use App\Http\Controllers\Web\SaleImportController;
use App\Http\Controllers\Web\SupplierController;
use App\Http\Controllers\Web\UploadController;
use Illuminate\Support\Facades\Route;

/*
 * The landing page, resolved by account kind rather than redirected blindly.
 *
 * A plain redirect to /dashboard is right for an administrator and wrong for
 * everyone else: an ordinary user would arrive to a 403, which reads as a broken
 * link rather than as "this is not yours". Their landing page is the account
 * page, the one page every signed-in account can open.
 */
Route::get('/', function () {
    $user = auth()->user();

    if ($user === null) {
        return redirect()->route('login');
    }

    return redirect()->route($user->is_admin ? 'dashboard' : 'account.show');
});

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);

    // Same limit as POST /api/account, which is the endpoint this form mirrors.
    Route::get('/register', [RegisterController::class, 'create'])->name('register');
    Route::post('/register', [RegisterController::class, 'store'])->middleware('throttle:6,1');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    // Self-service, open to every signed-in account. These four are the whole
    // of an ordinary user's application, and each acts on the caller alone.
    Route::get('/account', [AccountController::class, 'show'])->name('account.show');
    Route::patch('/account', [AccountController::class, 'update'])->name('account.update');
    Route::delete('/account', [AccountController::class, 'destroy'])->name('account.destroy');

    // The desk. Administrators only, declared once here rather than checked in
    // twenty controllers, so a page added later cannot be reachable by accident:
    // it lands in this group or it does not, and this group is the rule.
    Route::middleware('admin')->group(function (): void {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

        // Declared before the product routes so a literal /products/create can
        // never be read as a product id by the {product} binding below.
        Route::get('/products', [ProductController::class, 'index'])->name('products.index');
        Route::get('/products/create', [ProductController::class, 'create'])->name('products.create');
        Route::post('/products', [ProductController::class, 'store'])->name('products.store');
        Route::get('/products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
        Route::patch('/products/{product}', [ProductController::class, 'update'])->name('products.update');
        Route::delete('/products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');

        Route::get('/sales', [DirectoryController::class, 'sales'])->name('sales.index');
        Route::get('/sales/create', [SaleController::class, 'create'])->name('sales.create');
        Route::post('/sales', [SaleController::class, 'store'])->name('sales.store');

        // Before the {sale} route below, for the same reason the product routes
        // are in this order: a literal /sales/import would otherwise be matched as
        // a sale whose id is the word "import".
        Route::get('/sales/import', [SaleImportController::class, 'create'])->name('sales.import');

        // Reading a document calls a metered external model, so it carries its own
        // limit keyed on the account — see AppServiceProvider. Confirming the draft
        // writes only to this database and needs no ceiling beyond the row cap the
        // request enforces.
        Route::post('/sales/import/extract', [SaleImportController::class, 'extract'])
            ->middleware('throttle:imports')
            ->name('sales.import.extract');

        Route::post('/sales/import', [SaleImportController::class, 'store'])->name('sales.import.store');

        // The review screen is the response to the POST above, so its URL cannot be
        // served by a GET: reloading it, bookmarking it, or stepping forward to it
        // again arrives here, and without this route the framework answers 405. The
        // two differ only by method, which is why they share a path.
        Route::get('/sales/import/extract', [SaleImportController::class, 'refresh'])
            ->name('sales.import.extract.refresh');

        Route::get('/sales/{sale}', [SaleController::class, 'show'])->name('sales.show');
        Route::patch('/sales/{sale}/status', [SaleController::class, 'updateStatus'])->name('sales.status.update');

        Route::get('/customers', [DirectoryController::class, 'customers'])->name('customers.index');
        Route::get('/customers/{customer}/edit', [CustomerController::class, 'edit'])->name('customers.edit');
        Route::patch('/customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
        Route::patch('/customers/{customer}/profile', [CustomerController::class, 'updateProfile'])->name('customers.profile.update');
        Route::get('/suppliers', [DirectoryController::class, 'suppliers'])->name('suppliers.index');
        Route::get('/suppliers/{supplier}/edit', [SupplierController::class, 'edit'])->name('suppliers.edit');
        Route::patch('/suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');

        // Throttled: each call is a write to object storage, and the editor calls
        // it once per pasted image. A burst of pastes should not become a burst
        // of billed requests.
        Route::post('/admin/uploads', [UploadController::class, 'store'])
            ->middleware('throttle:30,1')
            ->name('admin.uploads.store');

        // Calls a paid external API, so it is limited per signed-in user rather
        // than per address: behind Vercel every visitor shares the proxy's IP
        // otherwise.
        Route::post('/assistant', [AssistantController::class, 'store'])
            ->middleware('throttle:assistant')
            ->name('assistant.store');
    });
});
