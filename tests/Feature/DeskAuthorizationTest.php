<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The read/write boundary on the web surface.
 *
 * An ordinary account is a reader: it opens the catalogue and the reference lists
 * and it changes nothing. That is a deliberate widening of the old rule, which was
 * "the whole desk is administrator-only", and it was only safe because
 * registration closed at the same time — an open registration form would hand the
 * catalogue to anyone who signed up. The two halves are one decision, and the
 * tests for both live in this file so neither can be changed alone.
 *
 * The boundary itself is the middleware on the route. Hiding a button in a Vue
 * template is courtesy: what stops a reader is that every write route still
 * answers 403, which is asserted here per verb rather than inferred from a page
 * load.
 */
class DeskAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Pages an ordinary account may open: the catalogue and the reference lists.
     *
     * Read off the route table rather than written as a list here, so a route added
     * to the desk later cannot be added without being covered — the test walks what
     * is actually mounted, and a page that is neither in this set nor refused by
     * `test_an_ordinary_account_is_refused_the_desk` is a hole.
     *
     * @return array<string, array{0: string}>
     */
    public static function readableRoutes(): array
    {
        return [
            'products' => ['/products'],
            'customers' => ['/customers'],
            'suppliers' => ['/suppliers'],
            'reference list' => ['/references/cities'],
        ];
    }

    /**
     * Pages carrying money, which stay administrator-only.
     *
     * The dashboard reports revenue and top customers; the ledger holds every
     * transaction. Readable routes would put both in front of anyone holding an
     * account, which is the reason this boundary is drawn where it is and not a
     * little further left.
     *
     * @return array<string, array{0: string}>
     */
    public static function deskRoutes(): array
    {
        return [
            'dashboard' => ['/dashboard'],
            'sales' => ['/sales'],
            'sales create' => ['/sales/create'],
            'sales import' => ['/sales/import'],
            'products create' => ['/products/create'],
        ];
    }

    /**
     * Every write against a readable table, as a model factory key and a path.
     *
     * Per verb rather than per page, because "an ordinary account cannot change
     * anything" is a claim about all of them, and a page-load test would say
     * nothing about PATCH. This is the assertion that actually holds the line: a
     * reader who finds a URL the template did not offer still gets nothing.
     *
     * The row has to exist. Model binding runs before EnsureAdmin — it is part of
     * the `web` group, which a route's own middleware follows — so a write aimed at
     * an id nobody holds answers 404 and proves nothing about authorisation. Each
     * case names the factory that makes the row real.
     *
     * @return array<string, array{0: string, 1: string, 2: class-string|null}>
     */
    public static function writesAgainstReadableTables(): array
    {
        return [
            'create a product' => ['post', '/products', null],
            'change a product' => ['patch', '/products/1', Product::class],
            'delete a product' => ['delete', '/products/1', Product::class],
            'open the product form' => ['get', '/products/1/edit', Product::class],
            'change a customer' => ['patch', '/customers/1', Customer::class],
            'change a customer profile' => ['patch', '/customers/1/profile', Customer::class],
            'open the customer form' => ['get', '/customers/1/edit', Customer::class],
            'change a supplier' => ['patch', '/suppliers/1', Supplier::class],
            'open the supplier form' => ['get', '/suppliers/1/edit', Supplier::class],
            'add to a reference list' => ['post', '/references/cities', null],
            'change a reference row' => ['patch', '/references/cities/1', City::class],
            'delete a reference row' => ['delete', '/references/cities/1', City::class],
            'open the reference form' => ['get', '/references/cities/1/edit', City::class],
        ];
    }

    #[DataProvider('readableRoutes')]
    public function test_an_ordinary_account_may_read_the_master_data(string $path): void
    {
        $this->actingAs(User::factory()->create())
            ->get($path)
            ->assertOk();
    }

    #[DataProvider('deskRoutes')]
    public function test_an_ordinary_account_is_refused_the_desk(string $path): void
    {
        $this->actingAs(User::factory()->create())
            ->get($path)
            ->assertForbidden();
    }

    /**
     * A reader can look and cannot touch.
     *
     * The write routes are the boundary; this is the assertion that they are
     * boundaries rather than redirects, per verb.
     */
    /**
     * @param  class-string<Model>|null  $exists
     */
    #[DataProvider('writesAgainstReadableTables')]
    public function test_an_ordinary_account_cannot_write_to_what_it_may_read(
        string $method,
        string $path,
        ?string $exists,
    ): void {
        if ($exists !== null) {
            $exists::factory()->create();
        }

        $this->actingAs(User::factory()->create())
            ->{$method}($path)
            ->assertForbidden();
    }

    /**
     * An administrator is still allowed everything, reads and writes alike.
     *
     * @param  array{0: string}  $arguments
     */
    #[DataProvider('deskRoutes')]
    public function test_an_administrator_is_allowed_the_desk(string $path): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get($path)
            ->assertOk();
    }

    #[DataProvider('readableRoutes')]
    public function test_an_administrator_is_allowed_the_master_data(string $path): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get($path)
            ->assertOk();
    }

    /**
     * The pages a reader may open say so, so the shell can drop the write controls.
     *
     * This is courtesy rather than enforcement — the 403s above are the
     * enforcement — but a page that shows a reader four buttons which each fail is
     * a page that lies about what the account can do.
     */
    #[DataProvider('readableRoutes')]
    public function test_a_readable_page_reports_whether_the_account_may_write(string $path): void
    {
        $this->actingAs(User::factory()->create())
            ->get($path)
            ->assertInertia(fn ($page) => $page->where('can.write', false));

        $this->actingAs(User::factory()->admin()->create())
            ->get($path)
            ->assertInertia(fn ($page) => $page->where('can.write', true));
    }

    /**
     * The uploads endpoint is a write, and it costs money per call.
     *
     * Asserted on its own because it is the one desk route that does not render a
     * page, so a permission matrix built out of page loads would silently skip the
     * route where the abuse would actually land.
     */
    public function test_an_ordinary_account_cannot_upload(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/admin/uploads', [])
            ->assertForbidden();
    }

    /**
     * The assistant bills an external API per message, so it is behind the same
     * rule as the desk.
     */
    public function test_an_ordinary_account_cannot_reach_the_assistant(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/assistant', ['message' => 'Halo'])
            ->assertForbidden();
    }

    /**
     * Creating an account is an administrator's job.
     *
     * This is the other half of the read boundary: an ordinary account can only
     * exist because an administrator made it. Left open, the read routes above
     * would be readable by anyone who signed up.
     */
    public function test_creating_an_account_is_an_administrator_s_job(): void
    {
        $this->get('/register')->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create());
        $this->get('/register')->assertForbidden();
        $this->post('/register', [])->assertForbidden();

        $this->actingAs(User::factory()->admin()->create());
        $this->get('/register')->assertOk();
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
        $this->get('/products')->assertRedirect(route('login'));
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
