<?php

namespace App\Http\Controllers\Api;

use App\Actions\DeleteAccount;
use App\Actions\UpdateAccount;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterAccountRequest;
use App\Http\Requests\UpdateAccountRequest;
use App\Http\Resources\AccountResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Account', description: 'Registration and self-service management of the authenticated account.')]
class AccountController extends Controller
{
    /**
     * Register a new account and hand back a usable token.
     *
     * Public and throttled, so the API can be exercised from a cold start with
     * no seeded user. Every other endpoint here is scoped to the caller.
     */
    #[OA\Post(
        path: '/api/account',
        summary: 'Register an account',
        description: 'Creates an account and returns a Sanctum token for it. Public and rate limited to 6 requests per minute.',
        security: [],
        tags: ['Account'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(type: 'object', required: ['name', 'email', 'password'], properties: [
                'name' => new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'Ada Lovelace'),
                'email' => new OA\Property(property: 'email', type: 'string', format: 'email', example: 'ada@example.com'),
                'password' => new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 8, example: 'correct-horse-battery'),
                'password_confirmation' => new OA\Property(property: 'password_confirmation', type: 'string', format: 'password', minLength: 8, example: 'correct-horse-battery', description: 'Must match `password`.'),
            ]),
        ),
        responses: [
            new OA\Response(response: 201, description: 'The account was created and a token issued.', content: new OA\JsonContent(type: 'object', properties: [
                'data' => new OA\Property(property: 'data', type: 'object', description: 'The new account.'),
                'token' => new OA\Property(property: 'token', type: 'string', example: '3|xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'),
            ])),
            new OA\Response(response: 422, description: 'Validation failed, including an email that is already registered.'),
        ],
    )]
    public function store(RegisterAccountRequest $request): JsonResponse
    {
        $user = User::create($request->validated());

        return (new AccountResource($user))
            ->additional(['token' => $user->createToken('swagger-ui')->plainTextToken])
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Display the authenticated account.
     */
    #[OA\Get(
        path: '/api/account',
        summary: 'Fetch the current account',
        tags: ['Account'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'The authenticated account.', content: new OA\JsonContent(ref: '#/components/schemas/Account')),
            new OA\Response(response: 401, description: 'Unauthenticated.'),
        ],
    )]
    public function show(Request $request): AccountResource
    {
        return new AccountResource($request->user());
    }

    /**
     * Update the authenticated account.
     *
     * Rotating the password also revokes every other token, so a stolen one
     * cannot outlive the change; the caller's own token survives.
     */
    #[OA\Patch(
        path: '/api/account',
        summary: 'Update the current account',
        description: 'Partial update: any field left out of the body is left unchanged. Changing the password requires `current_password` and revokes all other tokens.',
        tags: ['Account'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(type: 'object', properties: [
                'name' => new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'Ada Lovelace'),
                'email' => new OA\Property(property: 'email', type: 'string', format: 'email', example: 'ada@newdomain.test'),
                'current_password' => new OA\Property(property: 'current_password', type: 'string', format: 'password', example: 'correct-horse-battery', description: 'Required, and must match, whenever `password` is present. Ignored otherwise.'),
                'password' => new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 8, example: 'new-correct-horse'),
                'password_confirmation' => new OA\Property(property: 'password_confirmation', type: 'string', format: 'password', minLength: 8, example: 'new-correct-horse', description: 'Must match `password`.'),
            ]),
        ),
        responses: [
            new OA\Response(response: 200, description: 'The updated account.', content: new OA\JsonContent(ref: '#/components/schemas/Account')),
            new OA\Response(response: 401, description: 'Unauthenticated.'),
            new OA\Response(response: 422, description: 'Validation failed, including an email that is already taken or a `current_password` that does not match.'),
        ],
    )]
    public function update(UpdateAccountRequest $request, UpdateAccount $updateAccount): AccountResource
    {
        $user = $request->user();

        $updateAccount->handle($request, $user);

        return new AccountResource($user->refresh());
    }

    /**
     * Delete the authenticated account.
     */
    #[OA\Delete(
        path: '/api/account',
        summary: 'Delete the current account',
        tags: ['Account'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 204, description: 'Deleted.'),
            new OA\Response(response: 401, description: 'Unauthenticated.'),
        ],
    )]
    public function destroy(Request $request, DeleteAccount $deleteAccount): Response
    {
        $deleteAccount->handle($request->user());

        return response()->noContent();
    }
}
