<?php

namespace App\Http\Controllers\Api;

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
            new OA\QueryParameter(name: 'per_page', description: 'Items per page (max 100).', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 15)),
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
    public function index(Request $request): AnonymousResourceCollection
    {
        return ProductResource::collection(
            Product::query()->latest('id')->paginate(perPage: $this->perPage($request)),
        );
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
        return new ProductResource($product);
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
            ]),
        ),
        responses: [
            new OA\Response(response: 201, description: 'The created product.', content: new OA\JsonContent(ref: '#/components/schemas/Product')),
            new OA\Response(response: 401, description: 'Unauthenticated.'),
            new OA\Response(response: 422, description: 'Validation failed.'),
        ],
    )]
    public function store(StoreProductRequest $request): ProductResource
    {
        return new ProductResource(Product::create($request->validated()));
    }

    /**
     * Update the specified product. Omitted fields keep their current value.
     */
    #[OA\Patch(
        path: '/api/products/{product}',
        summary: 'Update a product',
        description: 'Partial update: any field left out of the body is left unchanged.',
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
    public function update(UpdateProductRequest $request, Product $product): ProductResource
    {
        $product->update($request->validated());

        return new ProductResource($product);
    }

    /**
     * Remove the specified product.
     */
    #[OA\Delete(
        path: '/api/products/{product}',
        summary: 'Delete a product',
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
    public function destroy(Product $product): Response
    {
        $product->delete();

        return response()->noContent();
    }

    /**
     * Resolve the requested page size, defaulting to 15 and capping at 100.
     */
    private function perPage(Request $request): int
    {
        return min(max($request->integer('per_page', 15), 1), 100);
    }
}
