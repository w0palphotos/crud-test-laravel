<?php

namespace App\Http\Controllers\Web;

use App\Actions\ListProducts;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductController extends Controller
{
    /**
     * Paginated product list, newest first, with an optional name filter.
     *
     * Ordering and the page size rules come from ListProducts, which the API uses
     * too, so the two views of the same data cannot disagree.
     */
    public function index(Request $request, ListProducts $listProducts): View
    {
        $search = trim((string) $request->query('q', ''));

        $products = $listProducts->handle($request, $search)->withQueryString();

        return view('products.index', [
            'products' => $products,
            'search' => $search,
        ]);
    }

    public function create(): View
    {
        return view('products.create');
    }

    public function store(StoreProductRequest $request): RedirectResponse
    {
        $product = Product::create($request->validated());

        return redirect()
            ->route('products.index')
            ->with('status', "Created \"{$product->name}\".");
    }

    public function edit(Product $product): View
    {
        return view('products.edit', ['product' => $product]);
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
