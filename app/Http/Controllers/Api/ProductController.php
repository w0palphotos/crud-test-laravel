<?php

namespace App\Http\Controllers\Api;

use App\Actions\DeleteProduct;
use App\Actions\ListProducts;
use App\Actions\SyncProductImages;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Products', description: 'CRUD operations on the product catalogue.')]
class ProductController extends Controller
{
    /**
     * Display a paginated listing of products.
     */
    #[OA\Get(
        path: '/api/products',
        summary: 'List products',
        tags: ['Products'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\QueryParameter(name: 'per_page', description: 'Items per page (max 100).', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: ListProducts::MAX_PER_PAGE, default: ListProducts::DEFAULT_PER_PAGE)),
            new OA\QueryParameter(name: 'page', description: 'Page number.', schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'A page of products.',
                content: new OA\JsonContent(type: 'object', properties: [
                    'data' => new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Product')),
                    'links' => new OA\Property(property: 'links', type: 'object', description: 'Laravel pagination links.'),
                    'meta' => new OA\Property(property: 'meta', type: 'object', description: 'Laravel pagination metadata.'),
                ]),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.'),
        ],
    )]
    public function index(Request $request, ListProducts $listProducts): AnonymousResourceCollection
    {
        return ProductResource::collection($listProducts->handle($request));
    }

    /**
     * Display the specified product.
     */
    #[OA\Get(
        path: '/api/products/{product}',
        summary: 'Fetch a product',
        tags: ['Products'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'product', description: 'Product ID.', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The product.', content: new OA\JsonContent(ref: '#/components/schemas/Product')),
            new OA\Response(response: 404, description: 'Product not found.'),
            new OA\Response(response: 401, description: 'Unauthenticated.'),
        ],
    )]
    public function show(Product $product): ProductResource
    {
        // load(), not loadMissing(): a caller that already eager-loaded the
        // gallery should not have it fetched again.
        return new ProductResource($product->load('images'));
    }

    /**
     * Store a newly created product.
     */
    #[OA\Post(
        path: '/api/products',
        summary: 'Create a product',
        tags: ['Products'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/Product', example: [
                'name' => 'Mechanical Keyboard',
                'description' => 'Hot-swappable 75% board.',
                'price' => 129.99,
                'stock' => 42,
                'images' => [
                    ['path' => 'uploads/8f14e45f-ceea-467a-9f8e-1a2f3c4d5e6f.jpg', 'alt_text' => 'Tampak atas'],
                ],
            ]),
        ),
        responses: [
            new OA\Response(response: 201, description: 'The created product.', content: new OA\JsonContent(ref: '#/components/schemas/Product')),
            new OA\Response(response: 401, description: 'Unauthenticated.'),
            new OA\Response(response: 422, description: 'Validation failed.'),
        ],
    )]
    public function store(StoreProductRequest $request, SyncProductImages $syncImages): ProductResource
    {
        // except('images') because the gallery is not a column on products: the
        // model is strict about what it accepts, and the rows are the action's job.
        $product = Product::create($request->safe()->except('images'));

        $syncImages->handle($product, $request->validated('images') ?? []);

        return new ProductResource($product->load('images'));
    }

    /**
     * Update the specified product. Omitted fields keep their current value.
     */
    #[OA\Patch(
        path: '/api/products/{product}',
        summary: 'Update a product',
        description: 'Partial update: any field left out of the body is left unchanged. The gallery follows the same rule — send `images` to replace it, leave it out to keep it, and send an empty list to empty it.',
        tags: ['Products'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'product', description: 'Product ID.', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/Product', example: ['stock' => 17]),
        ),
        responses: [
            new OA\Response(response: 200, description: 'The updated product.', content: new OA\JsonContent(ref: '#/components/schemas/Product')),
            new OA\Response(response: 404, description: 'Product not found.'),
            new OA\Response(response: 401, description: 'Unauthenticated.'),
            new OA\Response(response: 422, description: 'Validation failed.'),
        ],
    )]
    public function update(UpdateProductRequest $request, Product $product, SyncProductImages $syncImages): ProductResource
    {
        // except('images') for the same reason as store(): images is not a column.
        $product->update($request->safe()->except('images'));

        // Only when it was mentioned. Absent means untouched and an empty array
        // means emptied, and those are different intentions — collapsing them
        // would make a client that sends `stock` alone silently delete the gallery.
        if ($request->has('images')) {
            $syncImages->handle($product, $request->validated('images') ?? []);
        }

        return new ProductResource($product->load('images'));
    }

    /**
     * Remove the specified product.
     */
    #[OA\Delete(
        path: '/api/products/{product}',
        summary: 'Delete a product',
        description: 'Also deletes the files behind its gallery.',
        tags: ['Products'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'product', description: 'Product ID.', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Deleted.'),
            new OA\Response(response: 404, description: 'Product not found.'),
            new OA\Response(response: 401, description: 'Unauthenticated.'),
        ],
    )]
    public function destroy(Product $product, DeleteProduct $deleteProduct): Response
    {
        $deleteProduct->handle($product);

        return response()->noContent();
    }
}
