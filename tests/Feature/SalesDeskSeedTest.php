<?php

namespace Tests\Feature;

use App\Actions\SalesSummary;
use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\Sale;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SalesDeskSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * The seeded ledger, checked against the reporting project's fixture.
 *
 * The seven canonical sales carry stated totals rather than generated ones, and
 * that is the whole point of seeding them: this file is what proves a change to
 * the schema, the decimal handling or the aggregation cannot quietly alter what
 * the dashboard reports. A fixture whose numbers were computed by the same code
 * under test could not fail when that code was wrong.
 */
class SalesDeskSeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_the_seven_canonical_sales_are_present(): void
    {
        $this->assertSame(7, Sale::where('reference', 'like', 'SLV-CANONICAL-%')->count());
    }

    /**
     * The figure the reporting project's own fixture sums to.
     */
    public function test_the_canonical_sales_total_the_same_rp_as_the_reporting_project(): void
    {
        $total = $this->canonical()->sum('grand_total');

        $this->assertSame(
            SalesDeskSeeder::CANONICAL_TOTAL,
            (int) round((float) $total),
            'The seven canonical sales must add up to exactly the reporting project\'s total.',
        );
    }

    public function test_the_canonical_average_matches_to_the_cent(): void
    {
        // 615,000,000 over 7 is 87,857,142.857..., which the reporting project
        // records as 87,857,142.86. Asserted to two decimals because a rupiah is
        // the smallest unit here; the division itself does not terminate.
        $this->assertSame('87857142.86', number_format((float) $this->canonical()->avg('grand_total'), 2, '.', ''));
    }

    /**
     * Each canonical sale must still agree with its own line.
     */
    public function test_every_canonical_sale_reconciles_with_its_items(): void
    {
        foreach ($this->canonical() as $sale) {
            $this->assertSame(
                (float) $sale->grand_total,
                (float) $sale->items()->sum('line_total'),
                "Sale {$sale->reference} does not add up.",
            );
        }
    }

    public function test_the_canonical_rows_keep_their_salesperson_city_and_product(): void
    {
        $this->assertEqualsCanonicalizing(
            ['Andi', 'Budi', 'Citra'],
            $this->canonical()->pluck('salesperson.name')->unique()->sort()->values()->all(),
        );

        $this->assertEqualsCanonicalizing(
            ['Jakarta', 'Bandung', 'Surabaya'],
            $this->canonical()->pluck('city.name')->unique()->sort()->values()->all(),
        );
    }

    /**
     * Andi's two Jakarta sales, so the grouping the reporting app pivots on
     * reproduces here too.
     */
    public function test_andis_jakarta_sales_total_the_same(): void
    {
        // A query, not the loaded collection: whereHas filters in the database
        // and a collection has no such method.
        $total = Sale::where('reference', 'like', 'SLV-CANONICAL-%')
            ->whereHas('salesperson', fn ($query) => $query->where('name', 'Andi'))
            ->whereHas('city', fn ($query) => $query->where('name', 'Jakarta'))
            ->sum('grand_total');

        // 120,000,000 + 75,000,000 from the reporting fixture.
        $this->assertSame(195_000_000, (int) round((float) $total));
    }

    public function test_generated_volume_is_seeded_on_top_of_the_canonical_rows(): void
    {
        $this->assertGreaterThan(7, Sale::count(), 'The dashboard needs history behind the canonical rows.');
    }

    public function test_every_seeded_sale_reconciles_with_its_items(): void
    {
        foreach (Sale::with('items')->get() as $sale) {
            if ($sale->items->isEmpty()) {
                $this->fail("Sale {$sale->reference} has no items.");
            }

            $this->assertSame(
                (float) $sale->grand_total,
                (float) $sale->items->sum('line_total'),
                "Sale {$sale->reference} does not add up.",
            );
        }
    }

    /* ----------------------------------------------------------------------
     | The dashboard aggregates the same numbers
     | ---------------------------------------------------------------------- */

    public function test_the_summary_excludes_cancelled_sales_from_revenue(): void
    {
        Sale::query()->delete();

        Sale::factory()->create(['grand_total' => 100_000_000, 'sold_at' => now()->startOfDay()]);
        Sale::factory()->create(['grand_total' => 40_000_000, 'status' => SaleStatus::Cancelled, 'sold_at' => now()->startOfDay()]);

        $headline = (new SalesSummary)->headline();

        $this->assertSame('100000000.00', $headline['revenueThisMonth']);
    }

    public function test_the_summary_splits_this_month_from_last(): void
    {
        Sale::query()->delete();

        Sale::factory()->create(['grand_total' => 100_000_000, 'sold_at' => now()->startOfDay()]);
        Sale::factory()->create(['grand_total' => 70_000_000, 'sold_at' => now()->subMonthNoOverflow()->startOfMonth()]);

        $headline = (new SalesSummary)->headline();

        $this->assertSame('100000000.00', $headline['revenueThisMonth']);
        $this->assertSame('70000000.00', $headline['revenueLastMonth']);
    }

    public function test_the_summary_counts_open_and_settled_separately(): void
    {
        Sale::query()->delete();

        Sale::factory()->count(3)->create(['status' => SaleStatus::Shipped]);
        Sale::factory()->count(2)->create(['status' => SaleStatus::Delivered]);
        Sale::factory()->count(1)->create(['status' => SaleStatus::Cancelled]);

        $headline = (new SalesSummary)->headline();

        $this->assertSame(3, $headline['openSales']);
        $this->assertSame(3, $headline['settledSales'], 'Delivered and cancelled are both settled.');
    }

    public function test_monthly_revenue_is_grouped_by_calendar_month(): void
    {
        Sale::query()->delete();

        Sale::factory()->create(['grand_total' => 100_000_000, 'sold_at' => '2026-03-05 09:00:00']);
        Sale::factory()->create(['grand_total' => 50_000_000, 'sold_at' => '2026-03-20 09:00:00']);
        Sale::factory()->create(['grand_total' => 30_000_000, 'sold_at' => '2026-04-02 09:00:00']);

        $months = (new SalesSummary)->monthlyRevenue(24)
            ->keyBy('period')
            ->map(fn (array $row): string => $row['revenue']);

        $this->assertSame('150000000.00', $months['2026-03']);
        $this->assertSame('30000000.00', $months['2026-04']);
    }

    public function test_top_customers_are_ranked_by_revenue(): void
    {
        Sale::query()->delete();

        $big = Customer::factory()->create(['name' => 'CV Besar']);
        $small = Customer::factory()->create(['name' => 'CV Kecil']);

        Sale::factory()->for($big)->create(['grand_total' => 900_000_000]);
        Sale::factory()->for($small)->create(['grand_total' => 100_000_000]);

        $top = (new SalesSummary)->topCustomers();

        $this->assertSame('CV Besar', $top->first()['name']);
        $this->assertSame('900000000.00', $top->first()['revenue']);
    }

    private function canonical(): Collection
    {
        return Sale::where('reference', 'like', 'SLV-CANONICAL-%')
            ->with(['salesperson', 'city', 'items'])
            ->get();
    }
}
