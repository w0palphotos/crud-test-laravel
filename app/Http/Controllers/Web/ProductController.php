<?php

namespace App\Http\Controllers\Web;

use App\Actions\ListProducts;
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
            'products' => $products->through(fn (Product $product): array => [
                'id' => $product->id,
                'name' => $product->name,
                'description' => $product->description,
                'price' => (float) $product->price,
                'stock' => $product->stock,
                'edit_url' => route('products.edit', $product),
                'destroy_url' => route('products.destroy', $product),
            ]),
            'search' => $search,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Products/Create');
    }

    public function store(StoreProductRequest $request): RedirectResponse
    {
        $product = Product::create($request->validated());

        return redirect()
            ->route('products.index')
            ->with('status', "Created \"{$product->name}\".");
    }

    /**
     * @return array<string, mixed>
     */
    public function edit(Product $product): Response
    {
        return Inertia::render('Products/Edit', [
            'product' => $product,
            'update_url' => route('products.update', $product),
        ]);
    }

    /**
     * Partial update, so any field left out keeps its current value. The form
     * always submits every field, but the underlying rules are UpdateProductRequest's
     * and behave the same way the API's do.
     */
    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        $product->update($request->validated());

        return redirect()
            ->route('products.index')
            ->with('status', "Saved \"{$product->name}\".");
    }

    public function destroy(Product $product): RedirectResponse
    {
        $name = $product->name;
        $product->delete();

        return redirect()
            ->route('products.index')
            ->with('status', "Deleted \"{$name}\".");
    }
}
