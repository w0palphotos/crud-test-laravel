<?php

namespace App\Http\Controllers\Web;

use App\Actions\ListSales;
use App\Enums\SaleStatus;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\Tag;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The desk's three reference lists.
 *
 * They are one class because they are one job — reading a dimension table — and
 * splitting them into three controllers would give three files that differ only
 * in the table name and the columns chosen. The differences that matter are in
 * the queries, which the models own.
 *
 * Lists stay here; writes live in CustomerController and SupplierController so
 * validation, authorization, and relational side effects have their own focused
 * boundaries.
 */
class DirectoryController extends Controller
{
    /**
     * Whether the signed-in account may change what these pages show.
     *
     * Asked of the server on every one of these pages rather than read by each
     * component from the shared `auth` prop. The two are the same answer today,
     * but only one of them puts the question next to the data it is about — and
     * `auth.user.is_admin` is a flag on the session, not a capability, which is
     * what the middleware comment in HandleInertiaRequests says.
     *
     * @return array{write: bool}
     */
    private function can(Request $request): array
    {
        return ['write' => (bool) $request->user()->is_admin];
    }

    /**
     * @return array<string, mixed>
     */
    public function customers(Request $request): Response
    {
        $customers = Customer::query()
            ->with('profile.city')
            ->withCount(['sales'])
            ->when($search = trim((string) $request->query('q', '')), fn ($query) => $query
                ->where(function ($inner) use ($search): void {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                }))
            ->orderBy('name')
            ->paginate($this->perPage($request))
            ->withQueryString()
            ->through(fn (Customer $customer): array => [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'city' => $customer->profile?->city?->name,
                'creditLimit' => $customer->profile?->credit_limit,
                'paymentTerms' => $customer->profile?->payment_terms,
                // Null means nobody has onboarded them yet, which is not the same
                // as a zero limit, so the table shows them differently.
                'onboarded' => $customer->profile !== null,
                'salesCount' => $customer->sales_count,
                'editUrl' => route('customers.edit', $customer),
            ]);

        return Inertia::render('Customers/Index', [
            'can' => $this->can($request),
            'customers' => $customers,
            'search' => $search,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function suppliers(Request $request): Response
    {
        $suppliers = Supplier::query()
            ->withCount(['products'])
            ->when($search = trim((string) $request->query('q', '')), fn ($query) => $query
                ->where(function ($inner) use ($search): void {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('contact_email', 'like', "%{$search}%");
                }))
            ->orderBy('name')
            ->paginate($this->perPage($request))
            ->withQueryString()
            ->through(fn (Supplier $supplier): array => [
                'id' => $supplier->id,
                'name' => $supplier->name,
                'contactEmail' => $supplier->contact_email,
                'phone' => $supplier->phone,
                'productsCount' => $supplier->products_count,
                'editUrl' => route('suppliers.edit', $supplier),
            ]);

        return Inertia::render('Suppliers/Index', [
            'can' => $this->can($request),
            'suppliers' => $suppliers,
            'search' => $search,
        ]);
    }

    /**
     * The sales ledger.
     *
     * The status filter's options are built from the enum rather than written
     * out in the Vue component. They used to be duplicated there, which is how a
     * filter ends up offering "Selesai" for one status and the table below
     * rendering "Diterima" for the same row — two labels for one state, both
     * correct, disagreeing with each other.
     *
     * @return array<string, mixed>
     */
    public function sales(Request $request, ListSales $listSales): Response
    {
        return Inertia::render('Sales/Index', [
            'sales' => $listSales->handle($request)->withQueryString()->through(fn ($sale): array => [
                'id' => $sale->id,
                'reference' => $sale->reference,
                'customer' => $sale->customer->name,
                'salesperson' => $sale->salesperson->name,
                'city' => $sale->city->name,
                'status' => $sale->status->value,
                'statusLabel' => $sale->status->label(),
                'grandTotal' => $sale->grand_total,
                'soldAt' => $sale->sold_at->translatedFormat('d M Y'),
                'url' => route('sales.show', $sale),

                // The labels, not the ids. The ledger shows what a tag is
                // called; the id is only ever something a filter sends back.
                'tags' => $sale->tags
                    ->map(fn (Tag $tag): array => [
                        'id' => $tag->id,
                        'name' => $tag->name,
                        'colour' => $tag->colour,
                    ])
                    ->values()
                    ->all(),
            ]),
            'filters' => $listSales->filters($request),

            // The stored value and the label, kept together. The value is what
            // the query filters on and the label is what a person reads, so the
            // component cannot pair the wrong two.
            'statuses' => [
                ['value' => '', 'label' => 'Semua status'],
                ...collect(SaleStatus::cases())
                    ->map(fn (SaleStatus $status): array => [
                        'value' => $status->value,
                        'label' => $status->label(),
                    ])
                    ->all(),
            ],

            // The tag filter's options, on the same terms as the statuses above:
            // read here rather than written out in the component, for the same
            // reason. Colour rides along so the filter looks like the chips the
            // same tags are rendered as on a sale.
            'tags' => Tag::query()
                ->orderBy('name')
                ->get(['id', 'name', 'colour'])
                ->all(),
        ]);
    }

    private function perPage(Request $request): int
    {
        return min(max($request->integer('per_page', 15), 1), 100);
    }
}
