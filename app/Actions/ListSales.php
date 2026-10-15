<?php

namespace App\Actions;

use App\Enums\SaleStatus;
use App\Models\Sale;
use Carbon\CarbonImmutable;
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
        [$from, $to] = $this->range($request);

        return [
            'search' => trim((string) $request->query('q', '')),
            'status' => $this->status($request),
            'salesperson' => $request->integer('salesperson') ?: null,
            'city' => $request->integer('city') ?: null,
            'customer' => $request->integer('customer') ?: null,
            'tags' => $this->tags($request),
            'from' => $from,
            'to' => $to,
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
            // tags eager-loaded because the ledger renders them as chips on
            // every row. Without this the page runs one extra query per sale.
            ->with(['customer', 'salesperson', 'city', 'tags'])
            ->when($filters['search'] !== '', fn (Builder $query) => $this->applySearch($query, $filters['search']))
            ->when($filters['status'] instanceof SaleStatus, fn (Builder $query) => $query->where('status', $filters['status']->value))
            ->when($filters['salesperson'], fn (Builder $query, int $id) => $query->where('salesperson_id', $id))
            ->when($filters['city'], fn (Builder $query, int $id) => $query->where('city_id', $id))
            ->when($filters['customer'], fn (Builder $query, int $id) => $query->where('customer_id', $id))
            ->when($filters['tags'] !== [], fn (Builder $query) => $this->applyTags($query, $filters['tags']))

            // From the beginning of the start day. Comparing a timestamp column
            // against a date is the same comparison in both databases: the date
            // stands for its own midnight.
            ->when($filters['from'], fn (Builder $query, string $from) => $query->where('sold_at', '>=', $from))

            // To the end of the end day, which is the next day's midnight and not
            // the date itself. `<= 2026-03-01` would keep only the first instant of
            // the last day and drop everything the desk filed during it — every
            // sale on the closing day of the range, silently. Stopping at the next
            // midnight also assumes nothing about the column's precision, which
            // Postgres is free to store to the microsecond.
            ->when($filters['to'], fn (Builder $query, string $to) => $query->where(
                'sold_at',
                '<',
                CarbonImmutable::parse($to)->addDay()->toDateString(),
            ))
            ->latest('sold_at')
            ->latest('id');
    }

    /**
     * Keep only the sales carrying at least one of the chosen tags.
     *
     * whereHas rather than a join, because a join across a many-to-many returns
     * the sale row once per matching tag, so filtering on two tags would list
     * the sale that has both of them twice — and paginating a query that
     * duplicates rows undercounts the total it reports next to the list.
     *
     * Or rather than and, because a ledger filtered on two tags is read as
     * "either", the way a set of checkboxes is read. Requiring every tag would
     * quietly show nothing for a combination nobody expected to be empty.
     *
     * @param  list<int>  $tags
     */
    private function applyTags(Builder $query, array $tags): void
    {
        $query->whereHas(
            'tags',
            fn (Builder $tag) => $tag->whereIn('tags.id', $tags),
        );
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

    /**
     * The date range as an ordered pair, either end nullable.
     *
     * An inverted pair is read the other way round, and the ordered pair is what
     * gets reported as the filter's value. Both ends are the person's own figures,
     * so the only thing `from` after `to` can mean is that they were typed the
     * wrong way round — and an empty ledger is the one answer that is wrong, because
     * it looks exactly like a period in which nothing was sold. Reported back
     * ordered, the two inputs on screen correct themselves.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function range(Request $request): array
    {
        $from = $this->date($request, 'from');
        $to = $this->date($request, 'to');

        if ($from !== null && $to !== null && $from > $to) {
            return [$to, $from];
        }

        return [$from, $to];
    }

    /**
     * A date from the query string, normalised, or null when it names no day.
     *
     * Normalised rather than echoed, so `2026-3-1` — which a person may well type
     * into an address bar — is read as the first of March and reported back in the
     * one form the rest of the application writes days in. The date input always
     * sends that form; this is only for the URL someone assembled by hand.
     *
     * The warnings check is what refuses a day that does not exist. `2026-02-30`
     * parses, and PHP rolls it forward into March, so a value that merely parsed is
     * not a value that named a day — and reading it as the second of March would be
     * a range nobody chose.
     */
    private function date(Request $request, string $key): ?string
    {
        $value = trim((string) $request->query($key, ''));

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($date === false) {
            return null;
        }

        $problems = \DateTimeImmutable::getLastErrors();

        if (is_array($problems) && ($problems['warning_count'] > 0 || $problems['error_count'] > 0)) {
            return null;
        }

        return $date->format('Y-m-d');
    }

    /**
     * The chosen tag ids, coerced, de-duplicated and ordered.
     *
     * $request->integer() only ever reads one scalar, so an array arrives as
     * null and a plain cast would leave whatever the query string happened to
     * hold as strings. Coerced here rather than trusted, because the values end
     * up inside whereIn() and a non-numeric one is a database type error on a
     * page whose only mistake was a hand-edited URL.
     *
     * Sorted so the same set of tags always produces the same URL, which is what
     * makes a filtered ledger shareable and keeps the pagination links stable.
     *
     * @return list<int>
     */
    private function tags(Request $request): array
    {
        $raw = $request->query('tag');

        if (! is_array($raw)) {
            return [];
        }

        $ids = array_map('intval', $raw);

        $ids = array_unique(array_filter($ids, fn (int $id): bool => $id > 0));

        sort($ids);

        return array_values($ids);
    }
}
