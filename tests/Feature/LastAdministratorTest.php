<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The refusal to delete the deployment's last administrator.
 *
 * Promotion happens through a shell command, so an installation that deletes its
 * final administrator has nobody who can log in and ask for one back. That makes
 * this the one place where account deletion is not a thing a person may simply
 * do, and the boundary is worth pinning in both directions.
 */
class LastAdministratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_ordinary_account_may_delete_itself(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->delete('/account')->assertRedirect(route('login'));

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_an_administrator_may_delete_itself_while_another_remains(): void
    {
        User::factory()->admin()->create();
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->delete('/account')->assertRedirect(route('login'));

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertSame(1, User::where('is_admin', true)->count());
    }

    public function test_the_only_administrator_cannot_delete_itself(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)
            ->delete('/account')
            ->assertSessionHasErrors('account');

        $this->assertModelExists($user);
    }

    /**
     * The API path answers the same refusal with a body rather than a redirect,
     * because a token client has no session to be redirected to.
     */
    public function test_the_only_administrator_cannot_delete_itself_over_the_api(): void
    {
        $user = User::factory()->admin()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->deleteJson('/api/account')
            ->assertStatus(422);

        $this->assertModelExists($user);
    }

    /**
     * The message has to say what to do, not only that it was refused.
     *
     * A bare refusal leaves the person at a dead end: the account is still theirs,
     * so there is no obvious next step, and the recovery is not discoverable from
     * inside the interface.
     */
    public function test_the_refusal_names_the_way_out(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)
            ->from('/account')
            ->delete('/account')
            ->assertSessionHasErrors('account');

        $this->assertStringContainsString(
            'admin:ensure',
            session('errors')->first('account'),
        );
    }

    /**
     * Everything else about an administrator's account stays ordinary.
     *
     * The guard is on deletion alone. An account being an administrator must not
     * make it undeletable, immutable, or privileged anywhere else, or the rule
     * becomes a much larger surface than the one thing it is for.
     */
    public function test_an_administrator_is_otherwise_an_ordinary_account(): void
    {
        $user = User::factory()->admin()->create(['name' => 'Admin']);

        $this->actingAs($user)->patch('/account', ['name' => 'Renamed'])->assertRedirect(route('account.show'));

        $this->assertSame('Renamed', $user->fresh()->name);
    }

    /**
     * The flag is a column, not a claim, so it can be read by anything holding a
     * database connection — which is what makes the count in the guard honest.
     */
    public function test_the_flag_is_a_real_column(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'is_admin'));
    }
}
