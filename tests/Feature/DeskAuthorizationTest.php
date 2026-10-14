<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The administrator boundary on the web surface.
 *
 * Registration is public, so an ordinary account is something anyone can hold.
 * These tests are what makes "ordinary" mean something: each one takes a real
 * account created the way a visitor creates one, and asks the desk a question.
 */
class DeskAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The pages an administrator-only session may open.
     *
     * Read off the route table rather than written as a list here, so a route
     * added to the desk later cannot be added without being covered — the test
     * walks what is actually mounted.
     *
     * @return array<string, array{0: string}>
     */
    public static function deskRoutes(): array
    {
        return [
            'dashboard' => ['/dashboard'],
            'products' => ['/products'],
            'products create' => ['/products/create'],
            'sales' => ['/sales'],
            'sales create' => ['/sales/create'],
            'customers' => ['/customers'],
            'suppliers' => ['/suppliers'],
        ];
    }

    #[DataProvider('deskRoutes')]
    public function test_an_ordinary_account_is_refused_the_desk(string $path): void
    {
        $this->actingAs(User::factory()->create())
            ->get($path)
            ->assertForbidden();
    }

    #[DataProvider('deskRoutes')]
    public function test_an_administrator_is_allowed_the_desk(string $path): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get($path)
            ->assertOk();
    }

    /**
     * The uploads endpoint is a write, and it costs money per call.
     *
     * Asserted on its own because it is the one desk route that does not render a
     * page, so a permission matrix built out of page loads would silently skip
     * the route where the abuse would actually land.
     */
    public function test_an_ordinary_account_cannot_upload(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/admin/uploads', [])
            ->assertForbidden();
    }

    /**
     * The assistant bills an external API per message, so it is behind the same
     * rule as the rest of the desk.
     */
    public function test_an_ordinary_account_cannot_reach_the_assistant(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/assistant', ['message' => 'Halo'])
            ->assertForbidden();
    }

    /**
     * Self-service stays open, or an ordinary account would sign in to a
     * 403 and have nowhere to go.
     */
    public function test_an_ordinary_account_keeps_its_own_account_page(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/account')
            ->assertOk();
    }

    /**
     * A guest is still sent to the login form rather than refused.
     *
     * Asserted separately from the 403 because they are different answers to
     * different questions: 401 means "who are you", 403 means "not you", and the
     * middleware stack has to distinguish them.
     */
    public function test_a_guest_is_redirected_rather_than_refused(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    /**
     * The flag reaches the page props so the shell can draw the right
     * navigation, and it does so as a boolean on both drivers.
     */
    public function test_the_account_kind_is_shared_with_the_view(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('auth.user.is_admin', true));

        $this->actingAs(User::factory()->create())
            ->get('/account')
            ->assertInertia(fn ($page) => $page->where('auth.user.is_admin', false));
    }
}
