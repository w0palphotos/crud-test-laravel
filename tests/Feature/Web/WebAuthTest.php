<?php

namespace Tests\Feature\Web;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_root_redirects_to_the_product_list(): void
    {
        $this->get('/')->assertRedirect(route('products.index'));
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get('/products')->assertRedirect(route('login'));
        $this->get('/account')->assertRedirect(route('login'));
    }

    public function test_an_authenticated_user_reaches_the_pages(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/products')->assertOk();
        $this->get('/account')->assertOk();
    }

    public function test_it_signs_in_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery',
        ]);

        $response = $this->post('/login', [
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery',
        ]);

        $response->assertRedirect(route('products.index'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_it_rejects_a_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery',
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
            'password' => 'correct-horse-battery',
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
            'password' => 'correct-horse-battery',
        ]);

        $this->get('/login');
        $before = session()->getId();

        $this->post('/login', [
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery',
        ]);

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($before, session()->getId(), 'Session fixation: the id must change on login.');
    }

    public function test_it_signs_the_user_out(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_the_login_page_is_hidden_from_signed_in_users(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/login')->assertRedirect(route('products.index'));
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
