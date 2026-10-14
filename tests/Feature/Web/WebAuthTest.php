<?php

namespace Tests\Feature\Web;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebAuthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The landing page depends on the account kind.
     *
     * Both halves matter. Sending an ordinary account to the dashboard answers a
     * successful sign-in with a 403, which reads as a broken link rather than as
     * "this is not yours".
     */
    public function test_the_root_redirects_an_administrator_to_the_dashboard(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get('/')->assertRedirect(route('dashboard'));
    }

    public function test_the_root_redirects_an_ordinary_account_to_its_own_page(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/')->assertRedirect(route('account.show'));
    }

    public function test_the_root_redirects_a_guest_to_the_login_form(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get('/products')->assertRedirect(route('login'));
        $this->get('/account')->assertRedirect(route('login'));
    }

    public function test_an_administrator_reaches_the_desk_pages(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get('/products')->assertOk();
        $this->get('/dashboard')->assertOk();
    }

    /**
     * The account page is the one page every signed-in account can open, so it
     * is the landing page for an ordinary account and must not itself be behind
     * the administrator middleware.
     */
    public function test_an_ordinary_account_reaches_its_own_page(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/account')->assertOk();
    }

    public function test_it_signs_in_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery9',
        ]);

        $response = $this->post('/login', [
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery9',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('account.show'));
    }

    public function test_it_rejects_a_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery9',
        ]);

        $response = $this->from('/login')->post('/login', [
            'email' => 'ada@example.com',
            'password' => 'not-the-password',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_it_rejects_an_unknown_email(): void
    {
        $response = $this->from('/login')->post('/login', [
            'email' => 'nobody@example.com',
            'password' => 'correct-horse-battery9',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_it_requires_both_fields(): void
    {
        $this->post('/login', [])->assertSessionHasErrors(['email', 'password']);
        $this->assertGuest();
    }

    public function test_the_session_id_is_regenerated_on_login(): void
    {
        $user = User::factory()->create([
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery9',
        ]);

        $this->get('/login');
        $before = session()->getId();

        $this->post('/login', [
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery9',
        ]);

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($before, session()->getId(), 'Session fixation: the id must change on login.');
    }

    public function test_it_signs_the_user_out(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
        $this->get('/account')->assertRedirect(route('login'));
    }

    public function test_an_idle_session_expires_after_one_day(): void
    {
        User::factory()->create([
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery9',
        ]);

        $this->post('/login', [
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery9',
        ]);

        $this->travelTo(now()->addDay()->addHour(), function (): void {
            $this->flushSession();
            $this->app->make('auth')->forgetGuards();
            $this->get('/account')->assertRedirect(route('login'));
        });
    }

    public function test_remember_me_does_not_outlive_the_one_day_login(): void
    {
        User::factory()->create([
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery9',
        ]);

        $this->post('/login', [
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery9',
            'remember' => true,
        ]);

        $this->travelTo(now()->addDay()->addHour(), function (): void {
            $this->flushSession();
            $this->app->make('auth')->forgetGuards();
            $this->get('/account')->assertRedirect(route('login'));
        });
    }

    public function test_the_login_page_is_hidden_from_signed_in_users(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/login')->assertRedirect(route('account.show'));
    }

    public function test_the_login_page_sends_an_administrator_to_the_dashboard(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get('/login')->assertRedirect(route('dashboard'));
    }

    /**
     * The API is token-based and must keep answering 401 JSON rather than
     * redirecting to the web login form now that the web guard has one.
     */
    public function test_the_api_still_answers_401_json_for_guests(): void
    {
        $this->getJson('/api/products')->assertUnauthorized();
        $this->getJson('/api/account')->assertUnauthorized();
    }
}
