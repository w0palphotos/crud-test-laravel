<?php

namespace App\Ai\Tools;

use App\Models\Product;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Lets the assistant read the product catalogue.
 *
 * The model can only ask this one question in these three ways. It has no ability
 * to send SQL, choose a table, or write anything: every filter below maps to a
 * single named parameter on an Eloquent query, so the worst a hallucinated
 * argument can do is match nothing. Giving a model raw query access would let any
 * user phrase a question into a data exfiltration, so the boundary is deliberate.
 *
 * Results are capped and returned as JSON with only the four columns the assistant
 * needs. The full catalogue going into the context window would be slow, expensive
 * and, past a few hundred rows, useless: the model would be answering from a
 * truncated list without knowing it.
 */
class SearchProducts implements Tool
{
    /**
     * The largest number of products to return in one call.
     *
     * The catalogue is small enough that this covers it, and the cap keeps the
     * response inside the model's context when it is not.
     */
    private const MAX_RESULTS = 25;

    public function description(): string
    {
        return 'Read the product catalogue. Use it whenever the user asks which products exist, '
            .'about a price or a stock level, or to find a product by name or description. '
            .'Call it with no arguments to list the catalogue.';
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'keyword' => $schema->string()
                ->description('Match this text against the product name and description. Omit to match everything.')
                ->max(100),
            'max_price' => $schema->number()
                ->description('Only products priced at or below this amount. Omit for no price limit.')
                ->min(0),
            'in_stock_only' => $schema->boolean()
                ->description('Only products with stock greater than zero. Defaults to false.'),
            'out_of_stock_only' => $schema->boolean()
                ->description('Only products with zero stock. Defaults to false.'),
        ];
    }

    /**
     * Neutralise LIKE wildcards in a search term.
     *
     * A model asked to search for "%" will happily send one, and unescaped it is a
     * wildcard that matches every row, so a filter the user believes is narrow
     * returns the whole catalogue. The backslash is the default LIKE escape
     * character in Postgres, so this only affects the wildcard itself and leaves
     * an ordinary term matching exactly as before.
     */
    private function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);
    }

    public function handle(Request $request): string
    {
        // Read through all() rather than $request['key']: ArrayAccess::offsetGet
        // indexes the arguments array directly, so it throws when a key is absent,
        // and the model omits every optional argument it does not need.
        //
        // max_price is checked with is_numeric against the raw value rather than
        // through float(), because float() returns 0.0 for a missing key. A zero
        // price limit is a real filter, not an absent one, and treating the absent
        // case as zero hides every product in the catalogue.
        $arguments = $request->all();

        $keyword = trim((string) ($arguments['keyword'] ?? ''));
        $maxPrice = $arguments['max_price'] ?? null;
        $inStockOnly = (bool) ($arguments['in_stock_only'] ?? false);
        $outOfStockOnly = (bool) ($arguments['out_of_stock_only'] ?? false);

        $products = Product::query()
            ->when($keyword !== '', fn ($query) => $query->where(
                fn ($query) => $query
                    ->where('name', 'like', '%'.$this->escapeLike($keyword).'%')
                    ->orWhere('description', 'like', '%'.$this->escapeLike($keyword).'%'),
            ))
            ->when(is_numeric($maxPrice), fn ($query) => $query->where('price', '<=', (float) $maxPrice))
            ->when($inStockOnly, fn ($query) => $query->where('stock', '>', 0))
            ->when($outOfStockOnly, fn ($query) => $query->where('stock', '<=', 0))
            ->orderBy('id')
            ->limit(self::MAX_RESULTS)
            ->get(['id', 'name', 'description', 'price', 'stock']);

        if ($products->isEmpty()) {
            return 'No products matched. If the user asked for a specific product, say plainly that '
                .'the catalogue has nothing under that name rather than inventing one.';
        }

        // A cap that truncates silently is worse than one that is visible: the model
        // would report the catalogue in full having seen only part of it.
        $note = $products->count() >= self::MAX_RESULTS
            ? sprintf('Showing the first %d products; there may be more.', self::MAX_RESULTS)
            : sprintf('Found %d product(s).', $products->count());

        return $note."\n".$products->toJson(
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }
}
