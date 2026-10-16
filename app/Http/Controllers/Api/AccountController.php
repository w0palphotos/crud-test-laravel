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
     * Create an account for somebody else, and hand back a token for it.
     *
     * Administrator-only, and deliberately so. This endpoint was public while every
     * desk route refused an ordinary account, which made an account nobody wanted.
     * Once the catalogue became readable, an open registration endpoint meant any
     * stranger could sign up and read it.
     *
     * The token in the response is what makes this usable as an onboarding step
     * rather than a two-step one: an administrator hands over a working credential
     * instead of asking somebody to come back to the login page.
     */
    #[OA\Post(
        path: '/api/account',
        summary: 'Create an account',
        description: 'Creates an ordinary account — never an administrator — and returns a Sanctum token for it. Requires an administrator token. The password must be at least 12 characters and contain a letter and a number, and must not appear in known breach data.',
        // Declared, not merely described. There is no global default in this spec,
        // so an operation without it is documented as public — and Swagger UI would
        // then invite a reader to try it with no authorization at all. The sentence
        // in the description is what a person reads; this is what the tool reads.
        security: [['bearerAuth' => []]],
        tags: ['Account'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(type: 'object', required: ['name', 'email', 'password'], properties: [
                'name' => new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'Ada Lovelace'),
                'email' => new OA\Property(property: 'email', type: 'string', format: 'email', example: 'ada@example.com'),
                'password' => new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 12, example: 'correct-horse-battery9'),
                'password_confirmation' => new OA\Property(property: 'password_confirmation', type: 'string', format: 'password', minLength: 12, example: 'correct-horse-battery9', description: 'Must match `password`.'),
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
                'current_password' => new OA\Property(property: 'current_password', type: 'string', format: 'password', example: 'correct-horse-battery9', description: 'Required, and must match, whenever `password` is present. Ignored otherwise.'),
                'password' => new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 12, example: 'new-correct-horse9'),
                'password_confirmation' => new OA\Property(property: 'password_confirmation', type: 'string', format: 'password', minLength: 12, example: 'new-correct-horse9', description: 'Must match `password`.'),
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
