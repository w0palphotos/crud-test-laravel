<?php

namespace Tests\Feature\Web;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Creating an account, which is an administrator's job.
 *
 * This form used to be public: anyone who reached /register became an ordinary
 * account. That was fine while the desk was administrator-only — an account that
 * could do nothing was not worth having — but the moment the catalogue became
 * readable, an open form would have handed it to every stranger who signed up. So
 * the form moved into the admin group, and this suite is what holds that door shut.
 *
 * The change also removed the sign-in. The person filling the form in was the
 * account; now the person filling it in is an administrator making an account for
 * somebody else, so signing them in as that account would switch the administrator
 * out of their own session. Both halves are asserted below.
 */
class WebRegisterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery9',
            'password_confirmation' => 'correct-horse-battery9',
        ], $overrides);
    }

    /**
     * An administrator at the keyboard.
     */
    private function actingAsAdmin(): self
    {
        $this->actingAs(User::factory()->admin()->create());

        return $this;
    }

    /**
     * The door, which is the whole point.
     *
     * A guest and an ordinary account are both refused, and neither creates
     * anything. Asserted per actor because the two are different answers from
     * different middleware: `auth` sends a guest to the login form, while
     * `EnsureAdmin` refuses an account that is signed in and not an administrator.
     */
    public function test_the_form_is_not_reachable_by_a_guest_or_an_ordinary_account(): void
    {
        $this->get('/register')->assertRedirect(route('login'));
        $this->post('/register', $this->validPayload())->assertRedirect(route('login'));

        $this->assertDatabaseCount('users', 0);

        $this->actingAs(User::factory()->create());

        $this->get('/register')->assertForbidden();
        $this->post('/register', $this->validPayload())->assertForbidden();

        $this->assertDatabaseCount('users', 1);
    }

    public function test_an_administrator_sees_the_form(): void
    {
        $this->actingAsAdmin()
            ->get('/register')
            ->assertOk()
            ->assertViewIs('auth.register')
            ->assertSee('Buat akun');
    }

    public function test_it_creates_the_account(): void
    {
        $this->actingAsAdmin()
            ->from('/register')
            ->post('/register', $this->validPayload())
            ->assertRedirect(route('account.show'))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('users', ['email' => 'ada@example.com', 'name' => 'Ada Lovelace']);
    }

    /**
     * The administrator stays signed in, as themselves.
     *
     * The old flow logged the creator in as the account they had just made, which
     * was right when they were the same person and is a way to lose your own
     * session when they are not. The account signs in for itself afterwards.
     */
    public function test_the_administrator_who_made_the_account_stays_signed_in_as_themselves(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post('/register', $this->validPayload())
            ->assertRedirect(route('account.show'));

        $this->assertAuthenticatedAs($admin);
        $this->assertAuthenticatedAs($admin->fresh());
    }

    /**
     * Registration lands on the account page, not the dashboard.
     *
     * A new account is an ordinary one, and the desk is administrator-only, so
     * sending it to the dashboard would answer a successful registration with a
     * 403 — which is indistinguishable, to whoever just signed in, from the
     * registration having failed.
     */
    public function test_a_new_account_is_not_an_administrator(): void
    {
        $this->actingAsAdmin()->post('/register', $this->validPayload());

        $this->assertFalse(User::where('email', 'ada@example.com')->sole()->is_admin);
    }

    /**
     * Not mass-assignable, so an administrator cannot mint a peer by hand.
     *
     * Deliberate: the only route to an administrator is the admin:ensure command,
     * run from a shell. A web form that could do it would put the boundary in the
     * hands of whoever reached it.
     */
    public function test_a_stray_is_admin_field_cannot_create_an_administrator(): void
    {
        $this->actingAsAdmin()->post('/register', $this->validPayload(['is_admin' => true]));

        $this->assertFalse(User::where('email', 'ada@example.com')->sole()->is_admin);
    }

    public function test_it_stores_the_password_hashed(): void
    {
        $this->actingAsAdmin()->post('/register', $this->validPayload());

        $user = User::where('email', 'ada@example.com')->firstOrFail();

        $this->assertNotSame('correct-horse-battery9', $user->password);
        $this->assertTrue(Hash::check('correct-horse-battery9', $user->password));
    }

    /**
     * The new account is immediately usable.
     *
     * Asserted because nothing signs it in any more: if the hash were wrong the
     * only symptom would be an administrator reporting that the password they were
     * just given does not work, which is a support ticket rather than a test
     * failure.
     */
    public function test_the_account_can_sign_in_with_the_password_it_was_given(): void
    {
        $this->actingAsAdmin()->post('/register', $this->validPayload());

        $this->post('/logout');

        $this->post('/login', [
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery9',
        ])->assertRedirect(route('account.show'));
    }

    public function test_it_rejects_a_duplicate_email(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $this->actingAsAdmin()
            ->from('/register')
            ->post('/register', $this->validPayload())
            ->assertRedirect('/register')
            ->assertSessionHasErrors('email');

        $this->assertSame(1, User::where('email', 'ada@example.com')->count());
    }

    public function test_it_rejects_a_mismatched_confirmation(): void
    {
        $this->actingAsAdmin()
            ->from('/register')
            ->post('/register', $this->validPayload(['password_confirmation' => 'something-else']))
            ->assertRedirect('/register')
            ->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'ada@example.com']);
    }

    public function test_it_requires_a_name_email_and_password(): void
    {
        $this->actingAsAdmin()
            ->from('/register')
            ->post('/register', [])
            ->assertRedirect('/register')
            ->assertSessionHasErrors(['name', 'email', 'password']);

        $this->assertDatabaseMissing('users', ['email' => 'ada@example.com']);
    }

    public function test_it_rejects_a_password_that_is_too_short(): void
    {
        $this->actingAsAdmin()
            ->from('/register')
            ->post('/register', $this->validPayload([
                'password' => 'short',
                'password_confirmation' => 'short',
            ]))
            ->assertRedirect('/register')
            ->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'ada@example.com']);
    }

    public function test_it_keeps_the_typed_values_after_a_failure(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $this->actingAsAdmin()
            ->from('/register')
            ->post('/register', $this->validPayload())
            ->assertRedirect('/register');

        $this->assertSame('ada@example.com', session('_old_input')['email']);
    }

    /**
     * No throttle any more, and that is the intended change.
     *
     * The limit existed to match POST /api/account, and both of those doors were
     * public. An administrator making four accounts in a row is onboarding, not
     * abuse — and the limiter would have refused the fifth with a 429 that reads as
     * a bug rather than as a protection.
     */
    public function test_an_administrator_can_make_several_accounts_in_a_row(): void
    {
        $this->actingAsAdmin();

        foreach (range(1, 8) as $attempt) {
            $this->from('/register')
                ->post('/register', $this->validPayload([
                    'email' => "user{$attempt}@example.com",
                ]))
                ->assertRedirect(route('account.show'));
        }

        $this->assertSame(9, User::count());
    }

    /**
     * The login page must not offer a door that is now closed.
     *
     * A "Daftar di sini" link pointing at a 403 is a dead control in the one page
     * every visitor sees, so the link is gone rather than left to fail.
     */
    public function test_the_login_page_offers_no_way_to_register(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertDontSee(route('register'), false);
    }
}
