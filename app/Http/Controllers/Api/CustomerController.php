<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomerProfileRequest;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Rules\CustomerProfileValidation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

/**
 * The customers, over the API.
 *
 * Two write paths that mirror the browser exactly, because they are the same
 * writes: one for the customer's own three columns and one for the profile. They
 * are separate endpoints because they are separate lifecycles — see the customers
 * migration — and a client that wants to amend somebody's phone number should not
 * have to resend their credit limit to do it.
 *
 * Creating a customer writes both rows in one request, as the create form does.
 */
#[OA\Tag(name: 'Customers', description: 'The customer directory and the commercial profile attached to it.')]
class CustomerController extends Controller
{
    /**
     * Display a paginated listing of customers.
     */
    #[OA\Get(
        path: '/api/customers',
        summary: 'List customers',
        tags: ['Customers'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\QueryParameter(name: 'q', description: 'Matches name or email.', schema: new OA\Schema(type: 'string')),
            new OA\QueryParameter(name: 'per_page', description: 'Items per page (max 100).', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 15)),
            new OA\QueryParameter(name: 'page', description: 'Page number.', schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'A page of customers.',
                content: new OA\JsonContent(type: 'object', properties: [
                    'data' => new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Customer')),
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

        $customers = Customer::query()
            ->with('profile')
            ->withCount(['sales'])
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->whereLike('name', "%{$search}%")
                ->orWhereLike('email', "%{$search}%")))
            ->orderBy('name')
            ->paginate(min(max($request->integer('per_page', 15), 1), 100))
            ->withQueryString();

        return CustomerResource::collection($customers);
    }

    /**
     * Display the specified customer.
     */
    #[OA\Get(
        path: '/api/customers/{customer}',
        summary: 'Fetch a customer',
        tags: ['Customers'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'customer', description: 'Customer ID.', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The customer.', content: new OA\JsonContent(ref: '#/components/schemas/Customer')),
            new OA\Response(response: 404, description: 'Customer not found.'),
            new OA\Response(response: 401, description: 'Unauthenticated.'),
            new OA\Response(response: 403, description: 'Not an administrator.'),
        ],
    )]
    public function show(Customer $customer): CustomerResource
    {
        return new CustomerResource($customer->load(['profile.city']));
    }

    /**
     * Store a newly created customer, along with its commercial profile.
     */
    #[OA\Post(
        path: '/api/customers',
        summary: 'Create a customer',
        description: 'Writes the customer and its profile in one request. Leaving the profile fields out stores the column defaults — a zero credit limit and COD — rather than nulls.',
        tags: ['Customers'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/Customer', example: [
                'name' => 'Budi Santoso',
                'email' => 'budi@example.com',
                'phone' => '08123456789',
                'credit_limit' => 25000000,
                'payment_terms' => 'Net 30',
            ]),
        ),
        responses: [
            new OA\Response(response: 201, description: 'The created customer.', content: new OA\JsonContent(ref: '#/components/schemas/Customer')),
            new OA\Response(response: 401, description: 'Unauthenticated.'),
            new OA\Response(response: 403, description: 'Not an administrator.'),
            new OA\Response(response: 422, description: 'Validation failed.'),
        ],
    )]
    public function store(StoreCustomerRequest $request): CustomerResource
    {
        $customer = DB::transaction(function () use ($request): Customer {
            $customer = Customer::create($request->safe()->only(['name', 'email', 'phone']));

            $customer->profile()->create(CustomerProfileValidation::payload($request->validated()));

            return $customer;
        });

        return new CustomerResource($customer->load(['profile.city']));
    }

    /**
     * Update the specified customer. Omitted fields keep their current value.
     */
    #[OA\Patch(
        path: '/api/customers/{customer}',
        summary: 'Update a customer',
        description: 'Partial update: any field left out of the body is left unchanged. This endpoint touches the customer\'s own columns only — use the profile endpoint for the commercial terms.',
        tags: ['Customers'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'customer', description: 'Customer ID.', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/Customer', example: ['phone' => '0812000000']),
        ),
        responses: [
            new OA\Response(response: 200, description: 'The updated customer.', content: new OA\JsonContent(ref: '#/components/schemas/Customer')),
            new OA\Response(response: 404, description: 'Customer not found.'),
            new OA\Response(response: 401, description: 'Unauthenticated.'),
            new OA\Response(response: 403, description: 'Not an administrator.'),
            new OA\Response(response: 422, description: 'Validation failed.'),
        ],
    )]
    public function update(UpdateCustomerRequest $request, Customer $customer): CustomerResource
    {
        $customer->update($request->validated());

        return new CustomerResource($customer->load(['profile.city']));
    }

    /**
     * Create or replace the customer's commercial profile.
     */
    #[OA\Patch(
        path: '/api/customers/{customer}/profile',
        summary: 'Update a customer\'s commercial profile',
        description: 'Creates the profile if the customer does not have one, and amends it if they do.',
        tags: ['Customers'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'customer', description: 'Customer ID.', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/CustomerProfile', example: ['credit_limit' => 40000000]),
        ),
        responses: [
            new OA\Response(response: 200, description: 'The updated customer.', content: new OA\JsonContent(ref: '#/components/schemas/Customer')),
            new OA\Response(response: 404, description: 'Customer not found.'),
            new OA\Response(response: 401, description: 'Unauthenticated.'),
            new OA\Response(response: 403, description: 'Not an administrator.'),
            new OA\Response(response: 422, description: 'Validation failed.'),
        ],
    )]
    public function updateProfile(StoreCustomerProfileRequest $request, Customer $customer): CustomerResource
    {
        $customer->profile()->updateOrCreate(
            ['customer_id' => $customer->getKey()],
            CustomerProfileValidation::payload($request->validated()),
        );

        return new CustomerResource($customer->load(['profile.city']));
    }

    /**
     * Remove the specified customer.
     *
     * Refused while they have sales, which is the whole reason it is a 422 and
     * not a delete: `sales.customer_id` restricts, so the database would raise a
     * foreign key violation and the caller would get a 500 with no sentence in it.
     * Refusing says what is in the way.
     */
    #[OA\Delete(
        path: '/api/customers/{customer}',
        summary: 'Delete a customer',
        description: 'Refused with 422 while the customer still has sales.',
        tags: ['Customers'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\PathParameter(name: 'customer', description: 'Customer ID.', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Deleted.'),
            new OA\Response(response: 404, description: 'Customer not found.'),
            new OA\Response(response: 401, description: 'Unauthenticated.'),
            new OA\Response(response: 403, description: 'Not an administrator.'),
            new OA\Response(response: 422, description: 'The customer still has sales.'),
        ],
    )]
    public function destroy(Customer $customer): Response
    {
        if ($customer->sales()->exists()) {
            throw ValidationException::withMessages([
                'customer' => 'Pelanggan ini masih memiliki penjualan dan tidak bisa dihapus.',
            ]);
        }

        $customer->delete();

        return response()->noContent();
    }
}
