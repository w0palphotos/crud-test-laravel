<?php

namespace App\Actions;

use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The figures the dashboard shows.
 *
 * Every number is summed in the database rather than by loading rows and adding
 * them up in PHP. That is the difference between an indexed SUM over a range and
 * pulling fourteen months of sales into memory to reduce it.
 *
 * Cancelled sales are excluded from every revenue figure throughout. The money
 * was not taken, so counting it would overstate each month it appears in, and a
 * dashboard that overstates is worse than one that shows less.
 */
class SalesSummary
{
    /**
     * Revenue, sale count and delivery count per calendar month, oldest first.
     *
     * Months with no sales are absent rather than present with a zero. A bar
     * chart needs the gaps filled, and leaving that to the caller means the
     * gap-filling rule lives in one place instead of every chart that might want
     * one.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function monthlyRevenue(int $months = 12): Collection
    {
        return Sale::query()
            ->where('sold_at', '>=', now()->subMonthsNoOverflow($months)->startOfMonth())
            ->where('status', '!=', SaleStatus::Cancelled->value)
            ->select([
                DB::raw($this->monthExpression().' as period'),
                DB::raw('sum(grand_total) as revenue'),
                DB::raw('count(*) as sales_count'),
            ])
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->map(fn (Sale $row): array => [
                'period' => (string) $row->getAttribute('period'),
                'revenue' => number_format((float) $row->getAttribute('revenue'), 2, '.', ''),
                'salesCount' => (int) $row->getAttribute('sales_count'),
            ])
            ->values();
    }

    /**
     * Revenue and sale count for each of the last N months, with no gaps.
     *
     * monthlyRevenue() reports only the months that have sales, because that is
     * the honest shape of a grouped aggregate. A chart is not: an axis that skips
     * a month makes a quiet quarter look like a jump, and the gap-filling rule
     * belongs in one place rather than in every chart that might want one. So the
     * zero-filled series lives here, beside the aggregation that feeds it.
     *
     * @return list<array{period: string, label: string, revenue: string, salesCount: int}>
     */
    public function monthlySeries(int $months = 12): array
    {
        // One extra month of lookup because the series window and the aggregate
        // window start at different points: a sale on the first of the month can
        // fall in the month before the series begins.
        $rows = $this->monthlyRevenue($months + 1)->keyBy('period');

        $first = now()->subMonthsNoOverflow($months - 1)->startOfMonth();
        $series = [];
        $cursor = $first->copy();

        while ($cursor->lessThanOrEqualTo(now())) {
            $period = $cursor->format('Y-m');
            $row = $rows->get($period);

            $series[] = [
                'period' => $period,
                'label' => self::MONTH_LABELS[(int) $cursor->format('n') - 1].' '.$cursor->format('Y'),
                'revenue' => $row['revenue'] ?? '0.00',
                'salesCount' => $row['salesCount'] ?? 0,
            ];

            $cursor->addMonth();
        }

        return $series;
    }

    /**
     * Indonesian month abbreviations for the chart axis.
     *
     * A local array rather than translatedFormat(), which does now work for the
     * 'id' locale. The difference is not correctness but ownership: this is a fixed
     * twelve-element axis with no day or time on it, so naming it here keeps the
     * labels visible next to the series they label and leaves nothing to depend
     * on the locale being set at the moment the series is built. Anywhere a full
     * date is shown, translatedFormat() is the right call and is used instead.
     *
     * @var list<string>
     */
    private const MONTH_LABELS = [
        'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun',
        'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des',
    ];

    /**
     * The numbers at the top of the page.
     *
     * last month is included beside this month because a single figure cannot
     * tell you whether the business is doing better: the desk needs the
     * direction, not just the level.
     *
     * @return array<string, int|string>
     */
    public function headline(): array
    {
        return [
            'revenueThisMonth' => $this->sumRevenue(fn (Builder $query) => $query->where('sold_at', '>=', now()->startOfMonth())),
            'revenueLastMonth' => $this->sumRevenue(fn (Builder $query) => $query->where('sold_at', '>=', now()->subMonthNoOverflow()->startOfMonth())
                ->where('sold_at', '<', now()->startOfMonth())),
            'openSales' => Sale::whereNotIn('status', $this->statusValues(SaleStatus::settled()))->count(),
            'settledSales' => Sale::whereIn('status', $this->statusValues(SaleStatus::settled()))->count(),
            'customers' => Customer::count(),
            'outOfStockProducts' => Product::where('stock', '<=', 0)->count(),
        ];
    }

    /**
     * The customers who have spent the most, highest first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function topCustomers(int $limit = 5): Collection
    {
        return Sale::query()
            ->where('status', '!=', SaleStatus::Cancelled->value)
            ->join('customers', 'sales.customer_id', '=', 'customers.id')
            ->groupBy('customers.id', 'customers.name')
            ->orderByDesc(DB::raw('sum(sales.grand_total)'))
            ->limit($limit)
            ->get([
                'customers.id as customer_id',
                'customers.name as customer_name',
                DB::raw('sum(sales.grand_total) as revenue'),
                DB::raw('count(sales.id) as sales_count'),
            ])
            ->map(fn (Sale $row): array => [
                'id' => (int) $row->getAttribute('customer_id'),
                'name' => (string) $row->getAttribute('customer_name'),
                'revenue' => number_format((float) $row->getAttribute('revenue'), 2, '.', ''),
                'salesCount' => (int) $row->getAttribute('sales_count'),
            ]);
    }

    /**
     * The products that have sold least in the window, fewest units first.
     *
     * The desk's actual question when something looks wrong is which lines are
     * not moving, so this sorts ascending while the customer ranking sorts
     * descending. Out of stock is called out separately because a line nobody can
     * buy is a different problem from a line nobody wants.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function slowMovers(int $months = 6, int $limit = 5): Collection
    {
        return Sale::query()
            ->where('sold_at', '>=', now()->subMonthsNoOverflow($months)->startOfMonth())
            ->where('status', '!=', SaleStatus::Cancelled->value)
            ->join('sale_items', 'sale_items.sale_id', '=', 'sales.id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->groupBy('products.id', 'products.name', 'products.stock')
            ->orderBy('units_sold')
            ->limit($limit)
            ->get([
                'products.id as product_id',
                'products.name as product_name',
                'products.stock as stock',
                DB::raw('sum(sale_items.quantity) as units_sold'),
            ])
            ->map(fn (Sale $row): array => [
                'id' => (int) $row->getAttribute('product_id'),
                'name' => (string) $row->getAttribute('product_name'),
                'stock' => (int) $row->getAttribute('stock'),
                'unitsSold' => (int) $row->getAttribute('units_sold'),
            ]);
    }

    /**
     * @param  callable(Builder): void  $constraints
     */
    private function sumRevenue(callable $constraints): string
    {
        $query = Sale::query()->where('status', '!=', SaleStatus::Cancelled->value);

        $constraints($query->getQuery());

        return number_format((float) $query->sum('grand_total'), 2, '.', '');
    }

    /**
     * @param  list<SaleStatus>  $statuses
     * @return list<string>
     */
    private function statusValues(array $statuses): array
    {
        return array_map(fn (SaleStatus $status): string => $status->value, $statuses);
    }

    /**
     * Truncate a timestamp to its calendar month, as SQL.
     *
     * Both databases are in play — SQLite in development and in the default test
     * suite, Postgres in production — and they spell this differently. Writing
     * one driver's function and shipping it would pass every local test and fail
     * on the first real query after deploy.
     */
    private function monthExpression(): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => "to_char(sold_at, 'YYYY-MM')",
            'mysql', 'mariadb' => "date_format(sold_at, '%Y-%m')",
            default => "strftime('%Y-%m', sold_at)",
        };
    }
}
