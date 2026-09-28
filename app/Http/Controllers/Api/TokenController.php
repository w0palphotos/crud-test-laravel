<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IssueTokenRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Auth', description: 'Issue and revoke the Sanctum token used by the other endpoints.')]
class TokenController extends Controller
{
    /**
     * Exchange an email and password for a Sanctum personal access token.
     *
     * The token is then pasted once into Swagger UI's "Authorize" dialog and is
     * sent as a bearer token on every subsequent request.
     */
    #[OA\Post(
        path: '/api/auth/token',
        summary: 'Issue an API token',
        description: 'Returns a Sanctum personal access token to be sent as `Authorization: Bearer <token>`.',
        security: [],
        tags: ['Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(type: 'object', required: ['email', 'password'], properties: [
                'email' => new OA\Property(property: 'email', type: 'string', format: 'email', example: 'test@example.com'),
                'password' => new OA\Property(property: 'password', type: 'string', format: 'password', example: 'password'),
            ]),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'The issued token and the authenticated user.',
                content: new OA\JsonContent(type: 'object', properties: [
                    'token' => new OA\Property(property: 'token', type: 'string', example: '3|xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'),
                    'user' => new OA\Property(property: 'user', type: 'object', properties: [
                        'id' => new OA\Property(property: 'id', type: 'integer'),
                        'name' => new OA\Property(property: 'name', type: 'string'),
                        'email' => new OA\Property(property: 'email', type: 'string', format: 'email'),
                    ]),
                ]),
            ),
            new OA\Response(response: 422, description: 'Invalid credentials.'),
        ],
    )]
    public function store(IssueTokenRequest $request): JsonResponse
    {
        $credentials = $request->safe()->only(['email', 'password']);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        return response()->json([
            'token' => $user->createToken('swagger-ui')->plainTextToken,
            'user' => $user->only(['id', 'name', 'email']),
        ]);
    }

    /**
     * Revoke the token that authenticated the current request.
     */
    #[OA\Delete(
        path: '/api/auth/token',
        summary: 'Revoke the current token',
        tags: ['Auth'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 204, description: 'Revoked.'),
            new OA\Response(response: 401, description: 'Unauthenticated.'),
        ],
    )]
    public function destroy(Request $request): Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->noContent();
    }
}
