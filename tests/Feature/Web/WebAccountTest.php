<?php

namespace Tests\Feature\Web;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class WebAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_reach_the_account_page(): void
    {
        $this->get('/account')->assertRedirect(route('login'));
    }

    public function test_it_shows_the_account(): void
    {
        $this->actingAs(User::factory()->create([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
        ]));

        $this->get('/account')
            ->assertOk()
            ->assertSee('Ada Lovelace')
            ->assertSee('ada@example.com');
    }

    public function test_it_updates_the_name_and_email(): void
    {
        $user = User::factory()->create([
            'name' => 'Old Name',
            'email' => 'old@example.com',
        ]);
        $this->actingAs($user);

        $response = $this->patch('/account', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
        ]);

        $response->assertRedirect(route('account.show'));
        $response->assertSessionHas('status');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
        ]);
    }

    public function test_it_rejects_taking_another_users_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $user = User::factory()->create(['email' => 'ada@example.com']);
        $this->actingAs($user);

        $this->patch('/account', ['email' => 'taken@example.com'])
            ->assertSessionHasErrors('email');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => 'ada@example.com']);
    }

    public function test_it_changes_the_password_when_the_current_one_is_supplied(): void
    {
        $user = User::factory()->create(['password' => 'original-password']);
        $this->actingAs($user);

        $response = $this->patch('/account', [
            'current_password' => 'original-password',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ]);

        $response->assertRedirect(route('account.show'));
        $response->assertSessionHas('status');

        $this->assertTrue(
            Hash::check('brand-new-password', $user->fresh()->password),
            'The new password must be persisted.',
        );
    }

    public function test_it_refuses_a_password_change_without_the_current_one(): void
    {
        $user = User::factory()->create(['password' => 'original-password']);
        $this->actingAs($user);

        $this->patch('/account', [
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('original-password', $user->fresh()->password));
    }

    public function test_it_refuses_a_password_change_with_the_wrong_current_one(): void
    {
        $user = User::factory()->create(['password' => 'original-password']);
        $this->actingAs($user);

        $this->patch('/account', [
            'current_password' => 'not-the-current-one',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('original-password', $user->fresh()->password));
    }

    /**
     * A password change must not leave a previously issued bearer token working.
     * The session guard has no token of its own, so the shared action revokes
     * every token on the account rather than sparing the caller's.
     */
    public function test_changing_the_password_revokes_every_api_token(): void
    {
        $user = User::factory()->create(['password' => 'original-password']);
        $this->actingAs($user);
        $user->createToken('swagger-ui');
        $user->createToken('cli');

        $this->assertSame(2, $user->tokens()->count());

        $this->patch('/account', [
            'current_password' => 'original-password',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertRedirect(route('account.show'));

        $this->assertSame(0, $user->fresh()->tokens()->count());
    }

    public function test_changing_only_the_email_leaves_tokens_alone(): void
    {
        $user = User::factory()->create(['password' => 'original-password']);
        $this->actingAs($user);
        $user->createToken('swagger-ui');

        $this->patch('/account', ['email' => 'new@example.com'])->assertRedirect(route('account.show'));

        $this->assertSame(1, $user->fresh()->tokens()->count());
    }

    public function test_a_stray_current_password_is_not_persisted(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->patch('/account', [
            'name' => 'Renamed',
            'current_password' => 'irrelevant',
        ])->assertRedirect(route('account.show'));

        $this->assertArrayNotHasKey('current_password', $user->fresh()->getAttributes());
        $this->assertSame('Renamed', $user->fresh()->name);
    }

    public function test_it_deletes_the_account_and_its_tokens(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $user->createToken('swagger-ui');

        $this->delete('/account')->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
