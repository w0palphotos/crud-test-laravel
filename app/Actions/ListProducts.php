<?php

namespace App\Actions;

use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ListProducts
{
    public const DEFAULT_PER_PAGE = 15;

    public const MAX_PER_PAGE = 100;

    /**
     * A page of products, newest first.
     *
     * Shared by the API and the web UI so the two surfaces cannot disagree about
     * ordering or page size. Both previously expressed "newest first, 15 per page,
     * capped at 100" independently, which meant changing the default in one place
     * silently left the other wrong.
     *
     * $search is optional and only the web UI passes one today. The API keeps its
     * documented contract of a plain listing.
     */
    public function handle(Request $request, ?string $search = null): LengthAwarePaginator
    {
        return $this->query($request, $search)->paginate($this->perPage($request));
    }

    /**
     * The same ordering and filtering, without paginating.
     *
     * Exposed separately so a caller needing the count or the whole set is not
     * forced through a paginator.
     */
    public function query(Request $request, ?string $search = null): Builder
    {
        $search = trim((string) $search);

        return Product::query()
            ->when($search !== '', fn (Builder $query) => $query->where('name', 'like', "%{$search}%"))
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
}
