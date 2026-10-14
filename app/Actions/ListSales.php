<?php

namespace App\Actions;

use App\Enums\SaleStatus;
use App\Models\Sale;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * A page of sales, newest first, with the filters the sales desk list offers.
 *
 * Shared by the web page and anything else that needs the same rows, the same
 * way ListProducts is: expressing "newest first, fifteen to a page, capped at a
 * hundred" twice is how the two surfaces end up disagreeing about what a page of
 * data means.
 *
 * Every filter is optional and every one narrows the same query. They compose,
 * so leaving them all unset returns everything in the default order.
 */
class ListSales
{
    public const DEFAULT_PER_PAGE = 15;

    public const MAX_PER_PAGE = 100;

    /**
     * @return array<string, mixed>
     */
    public function filters(Request $request): array
    {
        return [
            'search' => trim((string) $request->query('q', '')),
            'status' => $this->status($request),
            'salesperson' => $request->integer('salesperson') ?: null,
            'city' => $request->integer('city') ?: null,
            'customer' => $request->integer('customer') ?: null,
        ];
    }

    public function handle(Request $request): LengthAwarePaginator
    {
        return $this->query($request)->paginate($this->perPage($request));
    }

    /**
     * The same filters and ordering, without paginating.
     *
     * Separate so the dashboard can total the whole matching set in one query
     * rather than paging through it to add up a number it already has.
     */
    public function query(Request $request): Builder
    {
        $filters = $this->filters($request);

        return Sale::query()
            ->with(['customer', 'salesperson', 'city'])
            ->when($filters['search'] !== '', fn (Builder $query) => $this->applySearch($query, $filters['search']))
            ->when($filters['status'] instanceof SaleStatus, fn (Builder $query) => $query->where('status', $filters['status']->value))
            ->when($filters['salesperson'], fn (Builder $query, int $id) => $query->where('salesperson_id', $id))
            ->when($filters['city'], fn (Builder $query, int $id) => $query->where('city_id', $id))
            ->when($filters['customer'], fn (Builder $query, int $id) => $query->where('customer_id', $id))
            ->latest('sold_at')
            ->latest('id');
    }

    /**
     * Resolve the requested page size, defaulting to 15 and capping at 100.
     */
    public function perPage(Request $request): int
    {
        return min(
            max($request->integer('per_page', self::DEFAULT_PER_PAGE), 1),
            self::MAX_PER_PAGE,
        );
    }

    /**
     * Matches the customer, the salesperson, the city or the reference.
     *
     * A free-text box over the whole sale is what a sales desk actually types
     * into — a customer name, or half a reference they read off an invoice —
     * rather than a field per column.
     */
    private function applySearch(Builder $query, string $search): void
    {
        $escaped = $this->escapeLike($search);

        $query->where(function (Builder $inner) use ($escaped): void {
            $inner->where('reference', 'like', "%{$escaped}%")
                ->orWhereHas('customer', fn (Builder $customer) => $customer->where('name', 'like', "%{$escaped}%"))
                ->orWhereHas('salesperson', fn (Builder $salesperson) => $salesperson->where('name', 'like', "%{$escaped}%"));
        });
    }

    /**
     * Escape the LIKE wildcards in a search term.
     *
     * A "%" typed into the box is a character someone is searching for, not a
     * wildcard. Left unescaped it matches every row, so a search the user
     * believes is narrow silently returns the whole ledger. The LIKE-escaped
     * behaviour of SearchProducts applies here for the same reason.
     */
    private function escapeLike(string $search): string
    {
        return addcslashes($search, '%_\\');
    }

    private function status(Request $request): ?SaleStatus
    {
        $status = (string) $request->query('status', '');

        return $status === '' ? null : SaleStatus::tryFrom($status);
    }
}
