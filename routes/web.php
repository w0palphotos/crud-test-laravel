<?php

use App\Http\Controllers\Web\AccountController;
use App\Http\Controllers\Web\AssistantController;
use App\Http\Controllers\Web\CustomerController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\DirectoryController;
use App\Http\Controllers\Web\HomeController;
use App\Http\Controllers\Web\LoginController;
use App\Http\Controllers\Web\ProductController;
use App\Http\Controllers\Web\ReferenceController;
use App\Http\Controllers\Web\RegisterController;
use App\Http\Controllers\Web\SaleController;
use App\Http\Controllers\Web\SaleDocumentController;
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
 *
 * A controller and not a closure, because a Closure here stops `route:cache`
 * serialising the whole route table — see HomeController.
 */
Route::get('/', HomeController::class)->name('home');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    // Self-service, open to every signed-in account. These four are the whole
    // of an ordinary user's application, and each acts on the caller alone.
    Route::get('/account', [AccountController::class, 'show'])->name('account.show');
    Route::patch('/account', [AccountController::class, 'update'])->name('account.update');
    Route::delete('/account', [AccountController::class, 'destroy'])->name('account.destroy');

    // Reading the master data is open to every signed-in account. The catalogue,
    // the customers, the suppliers and the reference lists are the vocabulary the
    // whole application is written in, and an account that cannot look at them has
    // nothing to read — it signs in to a single form and stops.
    //
    // This is a deliberate widening, and the reason it was safe to make is that
    // registration is no longer public: see the /register route below. With an open
    // registration form this group would hand the catalogue to anyone who signed
    // up, so the two decisions are one decision.
    //
    // Only reads. Every write to these same tables is in the admin group below, so
    // an ordinary account can look at a customer and still not change one.
    Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    Route::get('/customers', [DirectoryController::class, 'customers'])->name('customers.index');
    Route::get('/suppliers', [DirectoryController::class, 'suppliers'])->name('suppliers.index');
    Route::get('/references/{reference}', [ReferenceController::class, 'index'])->name('references.index');

    // The desk. Administrators only, declared once here rather than checked in
    // twenty controllers, so a page added later cannot be reachable by accident:
    // it lands in this group or it does not, and this group is the rule.
    //
    // The four reads above are deliberately outside it, which makes this group the
    // place writers live rather than the place the application lives.
    Route::middleware('admin')->group(function (): void {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

        // Declared before the product routes so a literal /products/create can
        // never be read as a product id by the {product} binding below.
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

        // The documents filed with the sale. The list is not a route of its own —
        // it travels with the sale's page, because a document is part of the sale
        // and the sale's page is where somebody looks for what it was made of.
        Route::post('/sales/{sale}/documents', [SaleDocumentController::class, 'store'])
            ->middleware('throttle:30,1')
            ->name('sales.documents.store');
        Route::delete('/sales/{sale}/documents/{document}', [SaleDocumentController::class, 'destroy'])
            ->name('sales.documents.destroy');

        // Amending a sale is a write on the same record the status route touches,
        // so the two sit together. The status keeps its own route and its own
        // request because moving through the lifecycle is checked against the
        // allowed transitions; this one cannot reach them.
        Route::patch('/sales/{sale}', [SaleController::class, 'update'])->name('sales.update');

        // The note on one entry in the history. Nested under the sale rather than
        // bound on its own so the event is looked up through it — the sale is what
        // says which events belong together.
        Route::patch('/sales/{sale}/status-events/{event}', [SaleController::class, 'updateStatusEvent'])
            ->name('sales.status-events.update');

        // Declared before the {customer} and {supplier} routes below, for the same
        // reason the product create route is: a literal /customers/create can
        // never be read as a customer whose id is the word "create".
        Route::get('/customers/create', [CustomerController::class, 'create'])->name('customers.create');
        Route::post('/customers', [CustomerController::class, 'store'])->name('customers.store');
        Route::get('/customers/{customer}/edit', [CustomerController::class, 'edit'])->name('customers.edit');
        Route::patch('/customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
        Route::patch('/customers/{customer}/profile', [CustomerController::class, 'updateProfile'])->name('customers.profile.update');
        Route::get('/suppliers/create', [SupplierController::class, 'create'])->name('suppliers.create');
        Route::post('/suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
        Route::get('/suppliers/{supplier}/edit', [SupplierController::class, 'edit'])->name('suppliers.edit');
        Route::patch('/suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');

        // The reference lists the desk adds to itself, one set of routes for all of
        // them. `{reference}` is a name from App\Support\ReferenceRegistry, resolved
        // to a model by the controller — never a table named by the request — so
        // this one declaration covers cities, salespeople and tags, and a seventh
        // list, without a seventh copy of any of it.
        //
        // `{row}` is constrained to a number because it is one, and an unconstrained
        // segment will swallow anything: `PATCH /references/tags/create` — what the
        // add form sent while its submit branch was misreading a ref — matched
        // `{row}` with the word "create" and was answered from inside the controller
        // as findOrFail(0). A 404 about a row nobody named is a worse answer than
        // the router's own, which says plainly that there is no such route.
        Route::get('/references/{reference}/create', [ReferenceController::class, 'create'])->name('references.create');
        Route::post('/references/{reference}', [ReferenceController::class, 'store'])->name('references.store');
        Route::get('/references/{reference}/{row}/edit', [ReferenceController::class, 'edit'])->whereNumber('row')->name('references.edit');
        Route::patch('/references/{reference}/{row}', [ReferenceController::class, 'update'])->whereNumber('row')->name('references.update');
        Route::delete('/references/{reference}/{row}', [ReferenceController::class, 'destroy'])->whereNumber('row')->name('references.destroy');

        // Throttled: each call is a write to object storage, and the editor calls
        // it once per pasted image. A burst of pastes should not become a burst
        // of billed requests.
        Route::post('/admin/uploads', [UploadController::class, 'store'])
            ->middleware('throttle:30,1')
            ->name('admin.uploads.store');

        // What the application can see of its own image storage. A read, so it is
        // not throttled, and it reports only whether a variable is set.
        Route::get('/admin/uploads/status', [UploadController::class, 'status'])
            ->name('admin.uploads.status');

        // Calls a paid external API, so it is limited per signed-in user rather
        // than per address: behind Vercel every visitor shares the proxy's IP
        // otherwise.
        Route::post('/assistant', [AssistantController::class, 'store'])
            ->middleware('throttle:assistant')
            ->name('assistant.store');

        // Creating an account, which is an administrator's job.
        //
        // This sat in the guest group until now, where anyone could reach it. That
        // is what made an ordinary account "something anyone can hold", and it is
        // the premise every 403 in this application rested on. With registration
        // closed, the reader routes above stop handing the catalogue to strangers.
        //
        // Deliberately reusing the existing form and the existing request class
        // instead of building an administrator-only account page: the validation,
        // the hashing and the failure round trip are written and tested already,
        // and this is the same form behind a stricter door.
        //
        // No throttle. It carried throttle:6,1 only to match POST /api/account,
        // and that limit existed because both doors were public. An administrator
        // making three accounts in a row is not abuse, and refusing the fourth
        // would be a puzzle rather than a protection.
        Route::get('/register', [RegisterController::class, 'create'])->name('register');
        Route::post('/register', [RegisterController::class, 'store']);
    });
});
