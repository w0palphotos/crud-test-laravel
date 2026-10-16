<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
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
            'password' => 'correct-horse-battery9',
            'password_confirmation' => 'correct-horse-battery9',
        ], $overrides);
    }

    /**
     * An administrator's bearer token, since creating an account now needs one.
     *
     * A row of its own, so every `assertDatabaseCount` below can be read as an
     * exact number. The alternative — reusing a caller — quietly changes what each
     * of those assertions is counting, and two of them failed before this was
     * made explicit.
     */
    private function adminTokenValue(): string
    {
        return User::factory()->admin()->create()->createToken('test')->plainTextToken;
    }

    /**
     * The door.
     *
     * An anonymous caller and an ordinary account's token are both refused, and
     * neither creates anything. This endpoint used to be public: it was harmless
     * while an account could do nothing, and it stopped being harmless the moment
     * the catalogue became readable, so it carries the same boundary the web form
     * does.
     */
    public function test_creating_an_account_requires_an_administrator_token(): void
    {
        $this->postJson('/api/account', $this->registration())->assertUnauthorized();
        $this->assertDatabaseCount('users', 0);

        $ordinary = User::factory()->create()->createToken('test')->plainTextToken;

        $this->withToken($ordinary)
            ->postJson('/api/account', $this->registration())
            ->assertForbidden();

        $this->assertDatabaseCount('users', 1);
    }

    public function test_it_creates_an_account_and_returns_a_usable_token(): void
    {
        $response = $this->withToken($this->adminTokenValue())
            ->postJson('/api/account', $this->registration());

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Ada Lovelace')
            ->assertJsonPath('data.email', 'ada@example.com')
            ->assertJsonStructure(['data' => ['id', 'name', 'email'], 'token']);

        $this->assertDatabaseHas('users', ['email' => 'ada@example.com']);

        // The application instance is shared across calls in a test, so the guard
        // still holds the administrator it resolved from the request above and
        // would answer with that account's details. Forgetting guards forces a real
        // re-authentication, which is the point: it is the new account's token being
        // exercised, not the admin's.
        $this->app->make('auth')->forgetGuards();

        $this->withToken($response->json('token'))
            ->getJson('/api/account')
            ->assertOk()
            ->assertJsonPath('data.email', 'ada@example.com');
    }

    public function test_it_never_returns_the_password(): void
    {
        $response = $this->withToken($this->adminTokenValue())
            ->postJson('/api/account', $this->registration());

        $this->assertArrayNotHasKey('password', $response->json('data'));
        $this->assertArrayNotHasKey('remember_token', $response->json('data'));
        $this->assertStringNotContainsString('correct-horse-battery9', $response->getContent());
    }

    public function test_it_rejects_a_duplicate_email(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $this->withToken($this->adminTokenValue())
            ->postJson('/api/account', $this->registration())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        // The existing account and the administrator, not three.
        $this->assertDatabaseCount('users', 2);
        $this->assertSame(1, User::where('email', 'ada@example.com')->count());
    }

    public function test_it_requires_a_matching_password_confirmation(): void
    {
        $this->withToken($this->adminTokenValue())
            ->postJson('/api/account', $this->registration(['password_confirmation' => 'something-else']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseMissing('users', ['email' => 'ada@example.com']);
    }

    public function test_it_enforces_a_minimum_password_length(): void
    {
        $this->withToken($this->adminTokenValue())
            ->postJson('/api/account', $this->registration([
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
            'password' => 'brand-new-password9',
            'password_confirmation' => 'brand-new-password9',
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
            'password' => 'brand-new-password9',
            'password_confirmation' => 'brand-new-password9',
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

        // assertDatabaseMissing would build `where current_password = 'whatever'`,
        // which is an error rather than a pass on a database where the column
        // correctly does not exist — and that database is the one in production.
        // The claim being made is about the schema, so it is asked of the schema.
        $this->assertFalse(Schema::hasColumn('users', 'current_password'));

        // And the rest of the payload still went through: a field being ignored
        // must not quietly cost the user their actual change.
        $this->assertDatabaseHas('users', ['name' => 'Renamed']);
    }

    public function test_it_changes_the_password_when_the_current_one_is_supplied(): void
    {
        Sanctum::actingAs(User::factory()->create(['password' => 'original-password']));

        $this->patchJson('/api/account', [
            'current_password' => 'original-password',
            'password' => 'brand-new-password9',
            'password_confirmation' => 'brand-new-password9',
        ])->assertOk();

        $this->assertTrue(
            auth()->user()->fresh()->forceFill(['password' => 'brand-new-password9'])->exists()
                && password_verify('brand-new-password9', auth()->user()->fresh()->password),
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
                'password' => 'brand-new-password9',
                'password_confirmation' => 'brand-new-password9',
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
