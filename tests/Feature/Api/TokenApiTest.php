<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class TokenApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_issues_a_token_for_valid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => 'password',
        ]);

        $response = $this->postJson('/api/auth/token', [
            'email' => 'test@example.com',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.email', 'test@example.com')
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']]);

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_the_issued_token_authenticates_subsequent_requests(): void
    {
        User::factory()->create([
            'email' => 'test@example.com',
            'password' => 'password',
        ]);

        $token = $this->postJson('/api/auth/token', [
            'email' => 'test@example.com',
            'password' => 'password',
        ])->json('token');

        $this->withToken($token)
            ->getJson('/api/products')
            ->assertOk();
    }

    public function test_it_rejects_a_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'test@example.com',
            'password' => 'password',
        ]);

        $this->postJson('/api/auth/token', [
            'email' => 'test@example.com',
            'password' => 'wrong-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_it_rejects_an_unknown_email(): void
    {
        $this->postJson('/api/auth/token', [
            'email' => 'nobody@example.com',
            'password' => 'password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_it_requires_both_credentials(): void
    {
        $this->postJson('/api/auth/token', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_it_throttles_repeated_token_requests(): void
    {
        User::factory()->create([
            'email' => 'test@example.com',
            'password' => 'password',
        ]);

        foreach (range(1, 6) as $attempt) {
            $this->postJson('/api/auth/token', [
                'email' => 'test@example.com',
                'password' => 'password',
            ])->assertOk();
        }

        $this->postJson('/api/auth/token', [
            'email' => 'test@example.com',
            'password' => 'password',
        ])->assertStatus(429);
    }

    public function test_it_revokes_the_current_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('swagger-ui');

        $this->withToken($token->plainTextToken)
            ->deleteJson('/api/auth/token')
            ->assertNoContent();

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $token->accessToken->getKey(),
        ]);

        $this->assertFalse(
            PersonalAccessToken::findToken($token->plainTextToken) instanceof PersonalAccessToken,
        );
    }

    public function test_guests_cannot_revoke_a_token(): void
    {
        $this->deleteJson('/api/auth/token')->assertUnauthorized();
    }
}
