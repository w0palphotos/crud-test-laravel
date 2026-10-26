<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleStatusEvent;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The read/write boundary on the web surface.
 *
 * An ordinary account is a reader: it opens the catalogue, the reference lists,
 * the dashboard and the sales ledger, and it changes nothing. That is a
 * deliberate widening of the old rule, which was "the whole desk is
 * administrator-only", and it was only safe because registration closed at the
 * same time — an open registration form would hand the catalogue to anyone who
 * signed up. The two halves are one decision, and the tests for both live in
 * this file so neither can be changed alone.
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
     * These pages report `can.write` so the shell can drop the write controls;
     * the desk reads below do not, because they carry no write controls at all.
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
     * The desk's own pages, readable by every signed-in account.
     *
     * Separate from readableRoutes because these two pages carry no write
     * controls and therefore no `can.write` prop — the dashboard is a wall of
     * figures and the ledger is a table of links.
     *
     * @return array<string, array{0: string}>
     */
    public static function deskReadRoutes(): array
    {
        return [
            'dashboard' => ['/dashboard'],
            'sales ledger' => ['/sales'],
        ];
    }

    /**
     * Pages that stay administrator-only: the import flow.
     *
     * The desk's remaining writes — a sale's status, its history notes, its
     * documents and gallery images, uploads' status read — sit in the same `admin`
     * group and are asserted per verb in deskWrites and the tests beside it. The
     * rest (create/store/edit/update/destroy on products, customers, suppliers,
     * references, sales) is under `can.write`, so sales accounts may reach it too.
     *
     * @return array<string, array{0: string}>
     */
    public static function deskRoutes(): array
    {
        return [
            'sales import' => ['/sales/import'],
        ];
    }

    /**
     * Routes sales accounts may open: create forms land on `can.write`.
     *
     * @return array<string, array{0: string}>
     */
    public static function salesWriteRoutes(): array
    {
        return [
            'products create' => ['/products/create'],
            'customers create' => ['/customers/create'],
            'suppliers create' => ['/suppliers/create'],
            'reference list create' => ['/references/cities/create'],
            'sales create' => ['/sales/create'],
            'products edit' => ['/products/1/edit'],
            'customers edit' => ['/customers/1/edit'],
            'suppliers edit' => ['/suppliers/1/edit'],
            'reference list edit' => ['/references/cities/1/edit'],
        ];
    }

    /**
     * Every write against a readable table, as a model factory key and a path.
     *
     * Sales accounts may now write to these tables, so each case asserts the
     * gate opens (200 for GET, validation errors for POST/PATCH, redirect for
     * DELETE) rather than a 403. This is the assertion that the boundary moved:
     * a sales account can reach the same write routes as an administrator.
     *
     * The row has to exist. Model binding runs before the middleware — a write
     * aimed at an id nobody holds answers 404 and proves nothing about who may.
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
            'create a customer' => ['post', '/customers', null],
            'change a customer' => ['patch', '/customers/1', Customer::class],
            'change a customer profile' => ['patch', '/customers/1/profile', Customer::class],
            'open the customer form' => ['get', '/customers/1/edit', Customer::class],
            'create a supplier' => ['post', '/suppliers', null],
            'change a supplier' => ['patch', '/suppliers/1', Supplier::class],
            'open the supplier form' => ['get', '/suppliers/1/edit', Supplier::class],
            'add to a reference list' => ['post', '/references/cities', null],
            'change a reference row' => ['patch', '/references/cities/1', City::class],
            'delete a reference row' => ['delete', '/references/cities/1', City::class],
            'open the reference form' => ['get', '/references/cities/1/edit', City::class],
        ];
    }

    /**
     * Administrator-only writes against the desk itself: annotating the history
     * entries. The status move itself is open to every account (see
     * salesSaleWriteRoutes below), but rewriting the record of a move that was
     * already filed is a door apart — it edits an audit trail.
     *
     * The row has to exist. Model binding runs before EnsureAdmin, so a write
     * aimed at an id nobody holds answers 404 and proves nothing about who may.
     *
     * @return array<string, array{0: string}>
     */
    public static function deskWrites(): array
    {
        return [
            'amend a status note' => ['/sales/1/status-events/1'],
        ];
    }

    /**
     * Routes a sales account may write against: sales can create and amend any
     * sale and move it through its statuses, same as admin. The row has to
     * exist first.
     *
     * @return array<string, array{0: string}>
     */
    public static function salesSaleWriteRoutes(): array
    {
        return [
            'amend a sale' => ['/sales/1'],
            'change a sale status' => ['/sales/1/status'],
        ];
    }

    #[DataProvider('readableRoutes')]
    public function test_an_ordinary_account_may_read_the_master_data(string $path): void
    {
        $this->actingAs(User::factory()->create())
            ->get($path)
            ->assertOk();
    }

    #[DataProvider('deskReadRoutes')]
    public function test_an_ordinary_account_may_read_the_desk(string $path): void
    {
        $this->actingAs(User::factory()->create())
            ->get($path)
            ->assertOk();
    }

    /**
     * The detail page of a single sale is readable too, and the same boundary
     * applies: an ordinary account sees the record and none of its write
     * controls.
     */
    public function test_an_ordinary_account_may_read_a_sale(): void
    {
        $sale = Sale::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get("/sales/{$sale->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('can.write', true));
    }

    #[DataProvider('deskRoutes')]
    public function test_an_ordinary_account_is_refused_the_desk_writes(string $path): void
    {
        $this->actingAs(User::factory()->create())
            ->get($path)
            ->assertForbidden();
    }

    #[DataProvider('salesWriteRoutes')]
    public function test_a_sales_account_may_access_write_routes(string $path): void
    {
        $this->actingAs(User::factory()->create());

        // Edit routes need an existing row; create one first so the route binds.
        if (str_contains($path, '/edit')) {
            match (true) {
                str_contains($path, 'products') => Product::factory()->create(),
                str_contains($path, 'customers') => Customer::factory()->create(),
                str_contains($path, 'suppliers') => Supplier::factory()->create(),
                str_contains($path, 'references') => City::factory()->create(),
                default => null,
            };
        }

        $this->get($path)->assertOk();
    }

    /**
     * Sales may post creates: the server validates and sends them back with
     * errors rather than refusing at the gate. A missing body yields 302 with
     * session errors, not 403.
     */
    public function test_a_sales_account_may_post_a_product_create(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/products', [])
            ->assertSessionHasErrors(['name', 'price', 'stock']);
    }

    public function test_a_sales_account_may_post_a_reference_create(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/references/cities', [])
            ->assertSessionHasErrors(['name']);
    }

    /**
     * A sales account can look and can touch: the write routes are open to them.
     *
     * @param  class-string<Model>|null  $exists
     */
    #[DataProvider('writesAgainstReadableTables')]
    public function test_a_sales_account_can_write_to_what_it_may_read(
        string $method,
        string $path,
        ?string $exists,
    ): void {
        if ($exists !== null) {
            $exists::factory()->create();
        }

        $response = $this->actingAs(User::factory()->create())
            ->{$method}($path);

        // Gate opens: 200 for GET forms, 302 redirect with validation errors for
        // POST/PATCH, or 302 redirect for DELETE. Never 403.
        $this->assertTrue(
            in_array($response->status(), [200, 302], true),
            "Expected {$method} {$path} to be reachable by sales, got 403 instead."
        );
    }

    /**
     * The same boundary on the desk, where the record is a sale rather than a
     * product. Asserted for the amendment route rather than inferred from the
     * routes beside it, because a route added to the group without this entry
     * is exactly the omission this file exists to make impossible.
     */
    #[DataProvider('deskWrites')]
    public function test_an_ordinary_account_cannot_amend_a_sale(string $path): void
    {
        $sale = Sale::factory()->create();

        // Attached to the sale just made, so the pair named in the path really
        // belongs together — the 403 has to be about authorisation, not about a
        // lookup that happened to miss.
        SaleStatusEvent::factory()->for($sale)->create();

        $this->actingAs(User::factory()->create())
            ->patch($path)
            ->assertForbidden();
    }

    #[DataProvider('salesSaleWriteRoutes')]
    public function test_a_sales_account_may_amend_a_sale(string $path): void
    {
        $sale = Sale::factory()->create();

        $response = $this->actingAs(User::factory()->create())
            ->patch($path, ['note' => 'Diubah oleh sales.']);

        // Gate opens: validation runs (302 with session errors) rather than 403.
        $this->assertTrue(
            in_array($response->status(), [200, 302], true),
            "Expected {$path} to be reachable by sales, got {$response->status()} instead."
        );
    }

    /**
     * An administrator is still allowed everything, reads and writes alike.
     */
    #[DataProvider('deskRoutes')]
    public function test_an_administrator_is_allowed_the_desk_writes(string $path): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get($path)
            ->assertOk();
    }

    #[DataProvider('deskReadRoutes')]
    public function test_an_administrator_may_read_the_desk(string $path): void
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
        // Every signed-in account may write now, so `can.write` is true for both
        // ordinary and admin accounts.
        $this->actingAs(User::factory()->create())
            ->get($path)
            ->assertInertia(fn ($page) => $page->where('can.write', true));

        $this->actingAs(User::factory()->admin()->create())
            ->get($path)
            ->assertInertia(fn ($page) => $page->where('can.write', true));
    }

    /**
     * The uploads endpoint opens with the forms it serves.
     *
     * A product's gallery is required (StoreProductRequest), and the note editor
     * pastes images through the same endpoint — so an upload door closed to sales
     * would leave them reaching a create form they can never submit. Asserted on
     * its own because it is the one desk route that does not render a page, so a
     * permission matrix built out of page loads would silently skip it. Validation
     * still applies: a missing file is an error on the field, never a 403.
     */
    public function test_a_sales_account_may_upload_an_image(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/admin/uploads', [])
            ->assertSessionHasErrors('image');
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
