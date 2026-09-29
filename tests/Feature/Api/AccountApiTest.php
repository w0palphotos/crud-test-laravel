<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Valid registration payload.
     *
     * @return array<string, mixed>
     */
    private function registration(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ], $overrides);
    }

    public function test_it_registers_an_account_and_returns_a_usable_token(): void
    {
        $response = $this->postJson('/api/account', $this->registration());

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Ada Lovelace')
            ->assertJsonPath('data.email', 'ada@example.com')
            ->assertJsonStructure(['data' => ['id', 'name', 'email'], 'token']);

        $this->assertDatabaseHas('users', ['email' => 'ada@example.com']);

        $this->withToken($response->json('token'))
            ->getJson('/api/account')
            ->assertOk()
            ->assertJsonPath('data.email', 'ada@example.com');
    }

    public function test_it_never_returns_the_password(): void
    {
        $response = $this->postJson('/api/account', $this->registration());

        $this->assertArrayNotHasKey('password', $response->json('data'));
        $this->assertArrayNotHasKey('remember_token', $response->json('data'));
        $this->assertStringNotContainsString('correct-horse-battery', $response->getContent());
    }

    public function test_it_rejects_a_duplicate_email(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $this->postJson('/api/account', $this->registration())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('users', 1);
    }

    public function test_it_requires_a_matching_password_confirmation(): void
    {
        $this->postJson('/api/account', $this->registration(['password_confirmation' => 'something-else']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_it_enforces_a_minimum_password_length(): void
    {
        $this->postJson('/api/account', $this->registration([
            'password' => 'short',
            'password_confirmation' => 'short',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_guests_cannot_reach_the_protected_account_endpoints(): void
    {
        $this->getJson('/api/account')->assertUnauthorized();
        $this->patchJson('/api/account', ['name' => 'Hacker'])->assertUnauthorized();
        $this->deleteJson('/api/account')->assertUnauthorized();
    }

    public function test_it_shows_only_the_callers_own_account(): void
    {
        $other = User::factory()->create(['email' => 'other@example.com']);
        Sanctum::actingAs(User::factory()->create(['email' => 'me@example.com']));

        $this->getJson('/api/account')
            ->assertOk()
            ->assertJsonPath('data.email', 'me@example.com')
            ->assertJsonMissing(['email' => $other->email]);
    }

    public function test_it_updates_only_the_supplied_fields(): void
    {
        Sanctum::actingAs(User::factory()->create([
            'name' => 'Old Name',
            'email' => 'old@example.com',
        ]));

        $this->patchJson('/api/account', ['name' => 'New Name'])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.email', 'old@example.com');

        $this->assertDatabaseHas('users', ['name' => 'New Name', 'email' => 'old@example.com']);
    }

    public function test_it_allows_updating_your_own_email_to_the_same_value(): void
    {
        Sanctum::actingAs(User::factory()->create(['email' => 'me@example.com']));

        $this->patchJson('/api/account', ['email' => 'me@example.com'])->assertOk();
    }

    public function test_it_rejects_taking_another_users_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        Sanctum::actingAs(User::factory()->create(['email' => 'me@example.com']));

        $this->patchJson('/api/account', ['email' => 'taken@example.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseHas('users', ['email' => 'taken@example.com']);
    }

    public function test_it_ignores_a_stray_current_password_when_no_password_is_being_changed(): void
    {
        Sanctum::actingAs(User::factory()->create(['name' => 'Old Name', 'password' => 'original-password']));

        $this->patchJson('/api/account', [
            'name' => 'New Name',
            'current_password' => 'not-even-the-current-one',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name');

        $this->assertTrue(password_verify('original-password', auth()->user()->fresh()->password));
    }

    public function test_it_refuses_a_password_change_without_the_current_password(): void
    {
        Sanctum::actingAs(User::factory()->create(['password' => 'original-password']));

        $this->patchJson('/api/account', [
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->assertTrue(password_verify('original-password', auth()->user()->fresh()->password));
    }

    public function test_it_refuses_a_password_change_with_the_wrong_current_password(): void
    {
        Sanctum::actingAs(User::factory()->create(['password' => 'original-password']));

        $this->patchJson('/api/account', [
            'current_password' => 'not-the-password',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->assertTrue(password_verify('original-password', auth()->user()->fresh()->password));
    }

    public function test_a_stray_current_password_is_not_persisted_as_a_field(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->patchJson('/api/account', [
            'name' => 'Renamed',
            'current_password' => 'whatever',
        ])->assertOk();

        $this->assertDatabaseMissing('users', ['current_password' => 'whatever']);
    }

    public function test_it_changes_the_password_when_the_current_one_is_supplied(): void
    {
        Sanctum::actingAs(User::factory()->create(['password' => 'original-password']));

        $this->patchJson('/api/account', [
            'current_password' => 'original-password',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertOk();

        $this->assertTrue(
            auth()->user()->fresh()->forceFill(['password' => 'brand-new-password'])->exists()
                && password_verify('brand-new-password', auth()->user()->fresh()->password),
        );
    }

    public function test_changing_the_password_revokes_other_tokens_but_keeps_the_callers(): void
    {
        $user = User::factory()->create(['password' => 'original-password']);
        $callerToken = $user->createToken('swagger-ui')->plainTextToken;
        $staleToken = $user->createToken('other-device')->plainTextToken;

        $this->withToken($callerToken)
            ->patchJson('/api/account', [
                'current_password' => 'original-password',
                'password' => 'brand-new-password',
                'password_confirmation' => 'brand-new-password',
            ])
            ->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertNotNull(PersonalAccessToken::findToken($callerToken), 'The caller keeps working after rotating their own password.');
        $this->assertNull(PersonalAccessToken::findToken($staleToken), 'The other device token must be revoked.');
    }

    public function test_changing_only_the_email_leaves_other_tokens_alone(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $other = $user->createToken('other-device')->plainTextToken;

        $this->patchJson('/api/account', ['email' => 'moved@example.com'])->assertOk();

        $this->assertNotNull(PersonalAccessToken::findToken($other));
    }

    public function test_it_deletes_the_account_and_its_tokens(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('swagger-ui')->plainTextToken;

        $this->withToken($token)->deleteJson('/api/account')->assertNoContent();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertNull(PersonalAccessToken::findToken($token));
    }

    public function test_a_deleted_account_token_no_longer_authenticates(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('swagger-ui')->plainTextToken;

        $this->withToken($token)->deleteJson('/api/account')->assertNoContent();

        // The application instance is shared across calls in a test, so the guard
        // would otherwise reuse the user it already resolved and never re-check
        // the token. Forgetting guards forces a real re-authentication.
        $this->app->make('auth')->forgetGuards();

        $this->withToken($token)->getJson('/api/account')->assertUnauthorized();
    }
}
