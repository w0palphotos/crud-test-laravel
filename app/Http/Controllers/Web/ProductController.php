<?php

namespace App\Http\Controllers\Web;

use App\Actions\DeleteProduct;
use App\Actions\ListProducts;
use App\Actions\SyncProductImages;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    /**
     * Paginated product list, newest first, with an optional name filter.
     *
     * Ordering and the page size rules come from ListProducts, which the API uses
     * too, so the two views of the same data cannot disagree.
     *
     * The paginator is serialised whole, so the page renders rows, a page count
     * and the prev/next URLs without a second request. Each row carries the URLs
     * its own links need, because the route shapes live in routes/web.php and
     * rebuilding them in the Vue component would be a second place to change.
     *
     * @return array<string, mixed>
     */
    public function index(Request $request, ListProducts $listProducts): Response
    {
        $search = trim((string) $request->query('q', ''));

        $products = $listProducts->handle($request, $search)->withQueryString();

        return Inertia::render('Products/Index', [
            // Whether this account may change anything, asked of the server rather
            // than read by the component from a prop it happens to have.
            //
            // The middleware is the boundary — a reader clicking a button that was
            // not hidden still gets a 403 — so this is courtesy rather than
            // security. It is the difference between a page honest about what this
            // account can do and one offering four controls that each fail.
            'can' => ['write' => (bool) $request->user()->is_admin],
            'products' => $products->through(fn (Product $product): array => [
                'id' => $product->id,
                'name' => $product->name,
                'description' => $product->description,
                'price' => (float) $product->price,
                'stock' => $product->stock,

                // The first row of the gallery, which is the one the relation
                // orders first, and null for a product with no pictures. Sent as
                // null rather than omitted so the component reads one key: a
                // missing image is the normal case here, not an edge case.
                'thumbnail_url' => $product->images->first()?->url,
                'edit_url' => route('products.edit', $product),
                'destroy_url' => route('products.destroy', $product),
            ]),
            'search' => $search,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Products/Create', [
            // An empty gallery rather than an absent prop, so the one component that
            // renders it has the same shape to read on both pages.
            'images' => [],
        ]);
    }

    public function store(StoreProductRequest $request, SyncProductImages $syncImages): RedirectResponse
    {
        // except('images') because the gallery is not a column on products: the model
        // is strict about what it accepts, and the rows are the action's job below.
        $product = Product::create($request->safe()->except('images'));

        $syncImages->handle($product, $request->validated('images') ?? []);

        return redirect()
            ->route('products.index')
            ->with('status', "Created \"{$product->name}\".");
    }

    /**
     * @return array<string, mixed>
     */
    public function edit(Product $product, SyncProductImages $syncImages): Response
    {
        return Inertia::render('Products/Edit', [
            'product' => $product,
            'update_url' => route('products.update', $product),
            'images' => $syncImages->gallery($product),
        ]);
    }

    /**
     * Partial update, so any field left out keeps its current value. The form
     * always submits every field, but the underlying rules are UpdateProductRequest's
     * and behave the same way the API's do.
     *
     * The gallery is the one thing that cannot be left out of that sentence by
     * accident: absent means untouched, and an empty array means emptied, so the two
     * are read apart here rather than collapsed by a default.
     */
    public function update(UpdateProductRequest $request, Product $product, SyncProductImages $syncImages): RedirectResponse
    {
        $product->update($request->safe()->except('images'));

        if ($request->has('images')) {
            $syncImages->handle($product, $request->validated('images') ?? []);
        }

        return redirect()
            ->route('products.index')
            ->with('status', "Saved \"{$product->name}\".");
    }

    public function destroy(Product $product, DeleteProduct $deleteProduct): RedirectResponse
    {
        $name = $product->name;

        $deleteProduct->handle($product);

        return redirect()
            ->route('products.index')
            ->with('status', "Deleted \"{$name}\".");
    }
}
