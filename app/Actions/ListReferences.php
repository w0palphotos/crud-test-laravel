<?php

namespace App\Actions;

use App\Support\ReferenceDefinition;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * A page of one reference list.
 *
 * The same shape as ListProducts, for the same reason it exists there: the search
 * box, the ordering and the page-size cap are one decision, and expressing them
 * here keeps them identical across every reference list instead of one copy per
 * table.
 */
class ListReferences
{
    public const DEFAULT_PER_PAGE = 15;

    public const MAX_PER_PAGE = 100;

    public function handle(Request $request, ReferenceDefinition $definition): LengthAwarePaginator
    {
        return $this->query($request, $definition)->paginate($this->perPage($request));
    }

    public function query(Request $request, ReferenceDefinition $definition): Builder
    {
        $search = trim((string) $request->query('q', ''));

        return $definition->instance()
            ->newQuery()
            // whereLike rather than where(...'like'): SQLite's LIKE folds case for
            // ASCII and Postgres's does not, so a search that finds a row on the
            // test database finds nothing on the engine production runs. whereLike
            // compiles to ILIKE on Postgres and COLLATE NOCASE on SQLite. See the
            // longer note on the same choice in ListProducts.
            ->when($search !== '', fn (Builder $query): Builder => $query->where(
                function (Builder $inner) use ($definition, $search): void {
                    foreach ($definition->searchable as $column) {
                        $inner->orWhereLike($column, "%{$search}%");
                    }
                }
            ))
            ->orderBy($definition->orderBy);
    }

    public function perPage(Request $request): int
    {
        return min(
            max($request->integer('per_page', self::DEFAULT_PER_PAGE), 1),
            self::MAX_PER_PAGE,
        );
    }
}
