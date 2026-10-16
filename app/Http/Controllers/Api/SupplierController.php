<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSupplierRequest;
use App\Http\Requests\UpdateSupplierRequest;
use App\Http\Resources\SupplierResource;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

/**
 * The suppliers, over the API.
 *
 * No product pairings here, and that is not an omission. What we pay a supplier
 * and how long they take belong to a supplier paired with one product, so they
 * live on `product_supplier` and are not something this endpoint can accept —
 * see Supplier::products().
 */
#[OA\Tag(name: 'Suppliers', description: 'The supplier directory.')]
class SupplierController extends Controller
{
    /**
     * Display a paginated listing of suppliers.
     */
    #[OA\Get(
        path: '/api/suppliers',
        summary: 'List suppliers',
        tags: ['Suppliers'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\QueryParameter(name: 'q', description: 'Matches name or contact email.', schema: new OA\Schema(type: 'string')),
            new OA\QueryParameter(name: 'per_page', description: 'Items per page (max 100).', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 15)),
            new OA\QueryParameter(name: 'page', description: 'Page number.', schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'A page of suppliers.',
                content: new OA\JsonContent(type: 'object', properties: [
                    'data' => new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Supplier')),
                    'links' => new OA\Property(property: 'links', type: 'object', description: 'Laravel pagination links.'),
                    'meta' => new OA\Property(property: 'meta', type: 'object', description: 'Laravel pagination metadata.'),
                ]),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.'),
            new OA\Response(response: 403, description: 'Not an administrator.'),
        ],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $search = trim((string) $request->query('q', ''));

        $suppliers = Supplier::query()
            ->withCount(['products'])
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->whereLike('name', "%{$search}%")
                ->orWhereLike('contact_email', "%{$search}%")))
            ->orderBy('name')
            ->paginate(min(max($request->integer('per_page', 15), 1), 100))
            ->withQueryString();

        return SupplierResource::collection($suppliers);
    }

    /**
     * Display the specified supplier.
     */
    #[OA\Get(
        path: '/api/suppliers/{supplier}',
        summary: 'Fetch a supplier',
        tags: ['Suppliers'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'supplier', description: 'Supplier ID.', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The supplier.', content: new OA\JsonContent(ref: '#/components/schemas/Supplier')),
            new OA\Response(response: 404, description: 'Supplier not found.'),
            new OA\Response(response: 401, description: 'Unauthenticated.'),
            new OA\Response(response: 403, description: 'Not an administrator.'),
        ],
    )]
    public function show(Supplier $supplier): SupplierResource
    {
        return new SupplierResource($supplier);
    }

    /**
     * Store a newly created supplier.
     */
    #[OA\Post(
        path: '/api/suppliers',
        summary: 'Create a supplier',
        tags: ['Suppliers'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/Supplier', example: [
                'name' => 'PT Sinar Abadi',
                'contact_email' => 'sales@sinarabadi.co.id',
                'phone' => '0215551234',
            ]),
        ),
        responses: [
            new OA\Response(response: 201, description: 'The created supplier.', content: new OA\JsonContent(ref: '#/components/schemas/Supplier')),
            new OA\Response(response: 401, description: 'Unauthenticated.'),
            new OA\Response(response: 403, description: 'Not an administrator.'),
            new OA\Response(response: 422, description: 'Validation failed.'),
        ],
    )]
    public function store(StoreSupplierRequest $request): SupplierResource
    {
        return new SupplierResource(Supplier::create($request->validated()));
    }

    /**
     * Update the specified supplier. Omitted fields keep their current value.
     */
    #[OA\Patch(
        path: '/api/suppliers/{supplier}',
        summary: 'Update a supplier',
        description: 'Partial update: any field left out of the body is left unchanged. Product pairings and their prices are untouched.',
        tags: ['Suppliers'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'supplier', description: 'Supplier ID.', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/Supplier', example: ['phone' => '0215559999']),
        ),
        responses: [
            new OA\Response(response: 200, description: 'The updated supplier.', content: new OA\JsonContent(ref: '#/components/schemas/Supplier')),
            new OA\Response(response: 404, description: 'Supplier not found.'),
            new OA\Response(response: 401, description: 'Unauthenticated.'),
            new OA\Response(response: 403, description: 'Not an administrator.'),
            new OA\Response(response: 422, description: 'Validation failed.'),
        ],
    )]
    public function update(UpdateSupplierRequest $request, Supplier $supplier): SupplierResource
    {
        $supplier->update($request->validated());

        return new SupplierResource($supplier);
    }

    /**
     * Remove the specified supplier.
     *
     * Refused while they supply anything, and this one is the worse of the two
     * cases. `product_supplier` cascades, so the delete would appear to succeed and
     * quietly strip the agreed price and lead time off every product this supplier
     * was backing — a silent rewrite of purchasing history, which is exactly the
     * thing ReferenceController's delete guard was written to prevent.
     */
    #[OA\Delete(
        path: '/api/suppliers/{supplier}',
        summary: 'Delete a supplier',
        description: 'Refused with 422 while the supplier is still paired with products.',
        tags: ['Suppliers'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'supplier', description: 'Supplier ID.', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Deleted.'),
            new OA\Response(response: 404, description: 'Supplier not found.'),
            new OA\Response(response: 401, description: 'Unauthenticated.'),
            new OA\Response(response: 403, description: 'Not an administrator.'),
            new OA\Response(response: 422, description: 'The supplier still supplies products.'),
        ],
    )]
    public function destroy(Supplier $supplier): Response
    {
        if ($supplier->products()->exists()) {
            throw ValidationException::withMessages([
                'supplier' => 'Supplier ini masih memasok produk dan tidak bisa dihapus.',
            ]);
        }

        $supplier->delete();

        return response()->noContent();
    }
}
