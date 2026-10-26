<?php

namespace App\Http\Middleware;

use App\Support\ReferenceDefinition;
use App\Support\ReferenceRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Define the props that are shared by default.
     *
     * Everything the page shell needs arrives here rather than being passed to
     * each controller: the navigation renders the signed-in user's name, and the
     * flash message that layouts/app.blade.php used to read from the session is
     * now a prop. Folding those into one place is what stops the shell drifting
     * from the pages it wraps.
     *
     * `errors` comes from parent::share(). Laravel turns a FormRequest failure
     * into a redirect carrying them, so the shared validation rules apply to an
     * Inertia form without any of the request classes knowing this exists.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),

            // always(), because it is a closure: without it Inertia would
            // evaluate the user once and carry a stale name through every
            // subsequent visit on this request.
            //
            // is_admin is read by the shell to decide which navigation to draw.
            // It is a flag, not a capability: it decides what the interface
            // offers, while the middleware on the route is what actually
            // refuses. Hiding a link is presentation; the 403 is the boundary.
            'auth' => [
                'user' => $request->user()?->only('id', 'name', 'email', 'is_admin'),
            ],

            // The session value the controllers flash after a write, such as
            // 'Created "Cast Iron Skillet".'. Inertia has no concept of a
            // flashed session value, so it is lifted into a prop here.
            'status' => Inertia::always($request->session()->get('status')),

            'appName' => config('app.name'),

            // Every URL the shell and the forms post to.
            //
            // route() is a Blade helper, so a Vue component has no way to reach
            // it. Handing the paths over keeps the route definitions in
            // routes/web.php where they can be read and changed in one place,
            // rather than reconstructing them as string literals in JavaScript.
            // Routes that take a model are not here: those are per-record, so
            // the controller that owns the rows builds them.
            //
            // login and register are absent because those two pages are still
            // Blade and call route() themselves. Every key listed here is read by
            // a component, which InertiaRoutePropsTest enforces in both directions
            // — an unread key is dead weight in the response of every page.
            'routes' => [
                'dashboard' => route('dashboard'),
                'products' => route('products.index'),
                'productsCreate' => route('products.create'),
                'productsStore' => route('products.store'),
                'sales' => route('sales.index'),
                'salesCreate' => route('sales.create'),
                'salesStore' => route('sales.store'),
                'salesImport' => route('sales.import'),
                'salesImportExtract' => route('sales.import.extract'),
                'salesImportStore' => route('sales.import.store'),
                'documentsStore' => route('admin.documents.store'),
                'customers' => route('customers.index'),
                'customersCreate' => route('customers.create'),
                'customersStore' => route('customers.store'),
                'suppliers' => route('suppliers.index'),
                'suppliersCreate' => route('suppliers.create'),
                'suppliersStore' => route('suppliers.store'),
                ...self::referenceRoutes(),
                'account' => route('account.show'),
                'accountUpdate' => route('account.update'),
                'accountDestroy' => route('account.destroy'),
                'register' => route('register'),
                'logout' => route('logout'),
                'assistant' => route('assistant.store'),
                'uploadsStore' => route('admin.uploads.store'),
                'activityLogs' => route('admin.activity-logs'),
                'adminLogs' => route('admin.logs'),
            ],

            // The reference lists, as the rail draws them. Here rather than written
            // into the sidebar so that adding a list to ReferenceRegistry is the
            // only edit needed: a list declared in Vue and one declared in PHP are
            // two places to forget, and the one forgotten renders as a link to a
            // route that does not exist.
            'referenceNav' => Inertia::always(self::referenceNav()),
        ];
    }

    /**
     * One navigation entry per reference list.
     *
     * @return list<array{key: string, label: string, icon: string}>
     */
    private static function referenceNav(): array
    {
        return array_values(array_map(
            fn (ReferenceDefinition $definition): array => [
                'key' => $definition->key,
                'label' => $definition->label,
                'icon' => $definition->icon,
            ],
            ReferenceRegistry::all(),
        ));
    }

    /**
     * One URL per reference list, keyed by `references.{key}`.
     *
     * Built by looping the registry rather than written out, so a list added to
     * App\Support\ReferenceRegistry reaches the rail without a second place to
     * remember. The key is the URL segment the path is built from, which is why
     * the sidebar can name `references.cities` and get a working href.
     *
     * @return array<string, string>
     */
    private static function referenceRoutes(): array
    {
        return collect(ReferenceRegistry::keys())
            ->mapWithKeys(fn (string $key): array => [
                "references.{$key}" => route('references.index', $key),
            ])
            ->all();
    }
}
