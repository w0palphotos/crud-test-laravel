<?php

namespace Tests\Feature\Web;

use App\Actions\SalesSummary;
use App\Enums\SaleStatus;
use App\Models\City;
use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Salesperson;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The dashboard page and the three directory lists the sidebar links to.
 *
 * The figures themselves are pinned in SalesDeskSeedTest against the reporting
 * project's fixture. What is checked here is the shape of what each page is
 * handed, because that is where a chart or a table breaks: a series with a gap in
 * it, a status bar that omits the states with no rows, or a customer that reads
 * as "no credit limit" when it actually means "never onboarded".
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private function acting(): void
    {
        $this->actingAs(User::factory()->admin()->create());
    }

    /* ----------------------------------------------------------------------
     | The dashboard
     | ---------------------------------------------------------------------- */

    public function test_a_guest_is_redirected_to_the_login_form(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_the_root_redirects_here(): void
    {
        $this->acting();

        $this->get('/')->assertRedirect(route('dashboard'));
    }

    public function test_it_renders_the_dashboard_component(): void
    {
        $this->acting();

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard/Index')
                ->hasAll(['headline', 'monthly', 'topCustomers', 'slowMovers', 'recentSales', 'lowStock', 'statusCounts'])
            );
    }

    /**
     * A chart axis that skips a month makes a quiet quarter look like a jump, so
     * the series has to arrive as one point per calendar month with no holes.
     */
    public function test_the_chart_series_has_one_point_per_month_and_no_gaps(): void
    {
        $this->acting();

        // Two sales, eleven months apart, so the months between them have to be
        // filled rather than skipped.
        Sale::factory()->create(['sold_at' => now()->subMonths(11)->startOfMonth()]);
        Sale::factory()->create(['sold_at' => now()->startOfMonth()]);

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $series = $page->toArray()['props']['monthly'];

                $this->assertCount(12, $series);

                $periods = array_column($series, 'period');
                $this->assertSame($periods, array_unique($periods), 'A month appears twice.');

                foreach ($series as $point) {
                    $this->assertMatchesRegularExpression('/^[0-9]{4}-[0-9]{2}$/', $point['period']);
                    $this->assertNotSame('', $point['label'], 'Every point needs an axis label.');
                    $this->assertArrayHasKey('revenue', $point);
                    $this->assertArrayHasKey('salesCount', $point);
                }

                // The filled months carry a zero rather than being absent.
                $empty = array_filter($series, fn (array $point): bool => $point['revenue'] === '0.00');

                $this->assertNotEmpty($empty, 'A quiet stretch should read as zero, not as a missing month.');
            });
    }

    /**
     * Every status is present with its count, including the ones with none.
     * A missing bar reads as "not a category" rather than as "none of them",
     * which is a different claim.
     */
    public function test_the_status_breakdown_covers_every_status(): void
    {
        $this->acting();

        Sale::factory()->count(2)->create(['status' => SaleStatus::Shipped]);

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('statusCounts.shipped', 2)
                ->where('statusCounts.delivered', 0)
                ->where('statusCounts.cancelled', 0)
                ->where('statusCounts.draft', 0)
            );
    }

    public function test_the_headline_carries_every_statistic_a_tile_reads(): void
    {
        $this->acting();

        $customer = Customer::factory()->create();
        $city = City::factory()->create();
        $salesperson = Salesperson::factory()->create();

        Sale::factory()->for($customer)->for($city)->for($salesperson)
            ->create(['grand_total' => 100_000_000, 'sold_at' => now()]);

        // Cancelled is money that was not taken, so it must not reach the revenue
        // figure the first tile shows.
        Sale::factory()->for($customer)->for($city)->for($salesperson)
            ->create(['grand_total' => 40_000_000, 'status' => SaleStatus::Cancelled, 'sold_at' => now()]);

        Product::factory()->create(['stock' => 0]);

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('headline.revenueThisMonth', '100000000.00')
                ->where('headline.customers', 1)
                ->where('headline.outOfStockProducts', 1)
                ->where('headline.openSales', 1)
                ->where('headline.settledSales', 1)
            );
    }

    public function test_recent_sales_come_with_everything_a_row_renders(): void
    {
        $this->acting();

        $customer = Customer::factory()->create(['name' => 'CV Contoh']);

        Sale::factory()->for($customer)->create(['reference' => 'SLV-EXAMPLE-01']);

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('recentSales', 1)
                ->where('recentSales.0.reference', 'SLV-EXAMPLE-01')
                ->where('recentSales.0.customer', 'CV Contoh')
                ->has('recentSales.0.statusLabel')
                ->has('recentSales.0.soldAt')
                ->has('recentSales.0.grandTotal')
                ->has('recentSales.0.url')
            );
    }

    public function test_recent_sales_link_to_the_sale_detail(): void
    {
        $this->acting();

        $sale = Sale::factory()->create();

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('recentSales.0.url', route('sales.show', $sale))
            );
    }

    public function test_recent_sales_expose_only_the_first_safe_note_image(): void
    {
        $this->acting();

        Sale::factory()->create(['note' => '<p>Nota</p>'
            .'<img src="https://cdn.contoh.co/uploads/pertama.png" alt="Nota pertama">'
            .'<img src="https://cdn.contoh.co/uploads/kedua.png" alt="Nota kedua">']);

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('recentSales.0.noteImage.url', 'https://cdn.contoh.co/uploads/pertama.png')
                ->where('recentSales.0.noteImage.alt', 'Nota pertama')
                ->where('recentSales.0.noteImage.count', 2)
            );
    }

    public function test_recent_sales_do_not_expose_an_unsafe_note_image(): void
    {
        $this->acting();

        Sale::factory()->create(['note' => '<p><img src="javascript:alert(1)" alt="Nota"></p>']);

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('recentSales.0.noteImage', null)
            );
    }

    public function test_low_stock_lists_only_what_needs_reordering(): void
    {
        $this->acting();

        Product::factory()->create(['name' => 'Habis', 'stock' => 0]);
        Product::factory()->create(['name' => 'Menipis', 'stock' => 3]);
        Product::factory()->create(['name' => 'Aman', 'stock' => 40]);

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $names = array_column($page->toArray()['props']['lowStock'], 'name');

                sort($names);

                $this->assertSame(['Habis', 'Menipis'], $names);
            });
    }

    /**
     * The monthly series and the raw aggregate must agree, or the chart would
     * quietly tell a different story from the figure beneath it.
     */
    public function test_the_chart_series_agrees_with_the_raw_monthly_aggregate(): void
    {
        $this->acting();

        Sale::factory()->create(['grand_total' => 120_000_000, 'sold_at' => now()->startOfMonth()]);

        $series = collect($this->get('/dashboard')->assertOk()->inertiaProps()['monthly'])
            ->keyBy('period');

        foreach ((new SalesSummary)->monthlyRevenue(12)->keyBy('period') as $period => $row) {
            $this->assertSame(
                $row['revenue'],
                $series[$period]['revenue'] ?? '0.00',
                "The chart and the aggregate disagree for {$period}."
            );
        }
    }

    /* ----------------------------------------------------------------------
     | The directory lists the sidebar links to
     | ---------------------------------------------------------------------- */

    public function test_guests_cannot_reach_the_directory(): void
    {
        $this->get('/sales')->assertRedirect(route('login'));
        $this->get('/customers')->assertRedirect(route('login'));
        $this->get('/suppliers')->assertRedirect(route('login'));
    }

    public function test_the_sales_list_uses_the_shared_listing_action(): void
    {
        $this->acting();

        $shipped = Sale::factory()->create(['status' => SaleStatus::Shipped]);

        Sale::factory()->count(2)->create(['status' => SaleStatus::Delivered]);

        $this->get('/sales?status=shipped')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Sales/Index')
                ->has('sales.data', 1)
                ->where('sales.data.0.id', $shipped->id)
                ->where('filters.status', 'shipped')
            );
    }

    /**
     * A customer with no profile is not a customer with a zero limit, so the two
     * have to be distinguishable in the props or the table cannot say so.
     */
    public function test_a_customer_without_a_profile_is_marked_unonboarded(): void
    {
        $this->acting();

        $onboarded = Customer::factory()->create();
        CustomerProfile::factory()->for($onboarded)->create();

        $plain = Customer::factory()->create();

        $this->get('/customers')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($onboarded, $plain): void {
                $byId = collect($page->toArray()['props']['customers']['data'])->keyBy('id');

                $this->assertTrue($byId[$onboarded->id]['onboarded']);
                $this->assertFalse($byId[$plain->id]['onboarded']);
                $this->assertNull($byId[$plain->id]['creditLimit']);
            });
    }

    public function test_the_supplier_list_counts_products(): void
    {
        $this->acting();

        $supplier = Supplier::factory()->create();
        $product = Product::factory()->create();

        $product->suppliers()->attach($supplier, [
            'unit_cost' => '100.00',
            'lead_time_days' => 1,
            'is_primary' => true,
        ]);

        $this->get('/suppliers')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Suppliers/Index')
                ->where('suppliers.data.0.id', $supplier->id)
                ->where('suppliers.data.0.productsCount', 1)
            );
    }

    public function test_the_directory_search_narrows_the_result(): void
    {
        $this->acting();

        Customer::factory()->create(['name' => 'CV Nusantara Jaya']);
        Customer::factory()->create(['name' => 'Toko Lain']);

        $this->get('/customers?q=Nusantara')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('customers.data', 1)
                ->where('customers.data.0.name', 'CV Nusantara Jaya')
                ->where('search', 'Nusantara')
            );
    }
}
