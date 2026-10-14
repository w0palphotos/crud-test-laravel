<?php

namespace App\Http\Controllers\Web;

use App\Actions\SalesSummary;
use App\Enums\SaleStatus;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Sale;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The sales desk landing page.
 *
 * Every figure comes from SalesSummary, which aggregates in the database rather
 * than loading rows to reduce them in PHP. The controller shapes and passes; it
 * computes nothing, so the same numbers cannot differ between the dashboard and
 * the list you clicked through from.
 */
class DashboardController extends Controller
{
    public function index(SalesSummary $summary): Response
    {
        return Inertia::render('Dashboard/Index', [
            'headline' => $summary->headline(),
            'monthly' => $summary->monthlySeries(),
            'topCustomers' => $summary->topCustomers(),
            'slowMovers' => $summary->slowMovers(),

            // The five most recent sales and the lines worth watching, pulled
            // here rather than lazily: a dashboard that renders in two rounds
            // shows a number and then changes it, which is worse than a slower
            // single load.
            'recentSales' => Sale::query()
                ->with(['customer', 'salesperson'])
                ->latest('sold_at')
                ->latest('id')
                ->limit(6)
                ->get()
                ->map(fn (Sale $sale): array => [
                    'id' => $sale->id,
                    'reference' => $sale->reference,
                    'customer' => $sale->customer->name,
                    'salesperson' => $sale->salesperson->name,
                    'city' => $sale->city->name,
                    'status' => $sale->status->value,
                    'statusLabel' => $sale->status->label(),
                    'grandTotal' => $sale->grand_total,
                    'soldAt' => $sale->sold_at->translatedFormat('d M Y'),
                    'url' => route('sales.show', $sale),
                    'noteImage' => $this->noteImage($sale->note),
                ]),

            'lowStock' => Product::query()
                // where, not whereColumn: whereColumn compares two columns, so
                // the 5 would be read as a column name — accepted silently by
                // SQLite, rejected by Postgres as `column "5" does not exist`.
                ->where('stock', '<=', 5)
                ->orderBy('stock')
                ->limit(6)
                ->get(['id', 'name', 'stock'])
                ->map(fn (Product $product): array => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'stock' => $product->stock,
                    'url' => route('products.edit', $product),
                ]),

            'statusCounts' => $this->statusCounts(),
        ]);
    }

    /**
     * The first safe image in an already-sanitised sale note.
     *
     * Only the first image is exposed because the dashboard row needs a
     * thumbnail, not the note itself. Sending the whole note would invite a
     * future `v-html` shortcut; parsing the stored HTML and returning one URL
     * keeps that sharp edge on the detail page, where it already belongs.
     *
     * The URL is checked again here. SanitiseRichText is the boundary that
     * should have removed anything unsafe, but a dashboard thumbnail is read by
     * everyone who opens the landing page, so a defence that costs one filter
     * call is worth keeping.
     *
     * @return array{url: string, alt: string, count: int}|null
     */
    private function noteImage(?string $note): ?array
    {
        if ($note === null || trim($note) === '') {
            return null;
        }

        $document = new \DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"><div>'.$note.'</div>');
        libxml_clear_errors();

        $images = $document->getElementsByTagName('img');

        if ($images->length === 0) {
            return null;
        }

        $url = trim($images->item(0)->getAttribute('src'));

        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $alt = trim($images->item(0)->getAttribute('alt'));

        return [
            'url' => $url,
            'alt' => $alt === '' ? 'Gambar catatan penjualan' : $alt,
            'count' => $images->length,
        ];
    }

    /**
     * How many sales sit at each status, for the stacked bar above the list.
     *
     * Grouped in one query: six separate count() calls would be six round trips
     * to render one row of a table.
     *
     * @return array<string, int>
     */
    private function statusCounts(): array
    {
        $counts = Sale::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $counts = $counts->map(fn ($total): int => (int) $total);

        // Every status present, zero included. A missing bar reads as "not a
        // category" rather than as "none of them", which is a different claim.
        return collect(SaleStatus::cases())
            ->mapWithKeys(fn (SaleStatus $status): array => [
                $status->value => $counts->get($status->value, 0),
            ])
            ->all();
    }
}
