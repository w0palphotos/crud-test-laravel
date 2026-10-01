<?php

namespace Tests\Feature\Web;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class WebRegisterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Drop the session and the resolved user, so the next request is a genuine
     * guest.
     *
     * Both halves are needed and neither is enough. flushSession() empties the
     * session but the auth guard still holds the user it already resolved, so the
     * guest middleware bounces the request to the product list and no account is
     * created. forgetGuards() alone leaves the session cookie, which the guard
     * would re-authenticate from. Together they model a second visitor.
     */
    private function startFreshSession(): void
    {
        $this->flushSession();
        $this->app->make('auth')->forgetGuards();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ], $overrides);
    }

    public function test_the_form_renders_for_a_guest(): void
    {
        $this->get('/register')
            ->assertOk()
            ->assertViewIs('auth.register')
            ->assertSee('Create an account');
    }

    public function test_the_form_is_hidden_from_signed_in_users(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/register')->assertRedirect(route('products.index'));
    }

    public function test_it_creates_the_account_and_signs_it_in(): void
    {
        $response = $this->post('/register', $this->validPayload());

        $response->assertRedirect(route('products.index'));
        $this->assertDatabaseHas('users', ['email' => 'ada@example.com', 'name' => 'Ada Lovelace']);
        $this->assertAuthenticated();
    }

    public function test_it_stores_the_password_hashed(): void
    {
        $this->post('/register', $this->validPayload());

        $user = User::where('email', 'ada@example.com')->firstOrFail();

        $this->assertNotSame('correct-horse-battery', $user->password);
        $this->assertTrue(Hash::check('correct-horse-battery', $user->password));
    }

    /**
     * Registering is as much a privilege change as signing in: the browser is
     * handed a new authenticated session, so a pre-existing session id must not
     * survive. The API has no session to fixate, which is why the login suite
     * already asserts this once and the register path needs its own.
     */
    public function test_the_session_id_is_regenerated_on_register(): void
    {
        $this->get('/register');
        $before = session()->getId();

        $this->post('/register', $this->validPayload());

        $this->assertNotSame($before, session()->getId());
    }

    public function test_it_rejects_a_duplicate_email(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $this->from('/register')
            ->post('/register', $this->validPayload())
            ->assertRedirect('/register')
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertSame(1, User::where('email', 'ada@example.com')->count());
    }

    public function test_it_rejects_a_mismatched_confirmation(): void
    {
        $this->from('/register')
            ->post('/register', $this->validPayload(['password_confirmation' => 'something-else']))
            ->assertRedirect('/register')
            ->assertSessionHasErrors('password');

        $this->assertGuest();
        $this->assertSame(0, User::where('email', 'ada@example.com')->count());
    }

    public function test_it_requires_a_name_email_and_password(): void
    {
        $this->from('/register')
            ->post('/register', [])
            ->assertRedirect('/register')
            ->assertSessionHasErrors(['name', 'email', 'password']);

        $this->assertGuest();
    }

    public function test_it_rejects_a_password_that_is_too_short(): void
    {
        $this->from('/register')
            ->post('/register', $this->validPayload([
                'password' => 'short',
                'password_confirmation' => 'short',
            ]))
            ->assertRedirect('/register')
            ->assertSessionHasErrors('password');

        $this->assertGuest();
    }

    public function test_it_keeps_the_typed_values_after_a_failure(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $this->from('/register')
            ->post('/register', $this->validPayload())
            ->assertRedirect('/register');

        $this->assertSame('ada@example.com', session('_old_input')['email']);
    }

    /**
     * Registration is the widest door in the deployment, so the form inherits the
     * same limit as the API endpoint it shares a request class with.
     *
     * Each attempt drops the previous session, because a real flood is separate
     * browser sessions from one address rather than one session creating accounts
     * in a loop. The limiter keys on the IP for guests, which is the state every
     * registration request is in, so the six allowed attempts are six accounts.
     */
    public function test_it_is_throttled_across_separate_sessions_from_one_address(): void
    {
        foreach (range(1, 6) as $attempt) {
            $this->startFreshSession();

            $this->post('/register', $this->validPayload([
                'email' => "user{$attempt}@example.com",
            ]))->assertRedirect(route('products.index'));

            $this->assertAuthenticatedAs(User::where('email', "user{$attempt}@example.com")->firstOrFail());
        }

        $this->startFreshSession();

        $this->post('/register', $this->validPayload(['email' => 'seventh@example.com']))
            ->assertStatus(429);

        $this->assertDatabaseMissing('users', ['email' => 'seventh@example.com']);
    }

    /**
     * A signed-in visitor is bounced off the form by the guest middleware, the same
     * redirect the login page gets. Asserted separately because the throttle test
     * above depends on it: without the bounce, its later attempts would be
     * redirected rather than counted, and the limit would look effective for the
     * wrong reason.
     */
    public function test_a_signed_in_visitor_cannot_post_a_second_registration(): void
    {
        $this->post('/register', $this->validPayload())->assertRedirect(route('products.index'));

        $this->post('/register', $this->validPayload(['email' => 'second@example.com']))
            ->assertRedirect(route('products.index'));

        $this->assertDatabaseMissing('users', ['email' => 'second@example.com']);
    }
}
