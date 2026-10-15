<?php

namespace App\Http\Controllers\Web;

use App\Actions\ImportSales;
use App\Http\Controllers\Controller;
use App\Http\Requests\ConfirmSalesImportRequest;
use App\Http\Requests\ExtractSalesImportRequest;
use App\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Importing sales from a document.
 *
 * A separate controller from SaleController rather than more methods on it,
 * because this is a different job with a different failure mode: SaleController
 * receives one sale that a person filled in, and every field on it has a value
 * because a person chose it. This one receives a file whose rows are unknown until
 * they are read, and whose problems are per row. Keeping the two apart keeps
 * SaleController's store the thin hand-off it is.
 *
 * Three steps rather than one. The upload is read into a draft and nothing is
 * written; the draft is shown for a person to correct; only the confirmed draft is
 * written. The middle step is not decoration — it is the only thing standing
 * between a model's reading of a document and the ledger, and it is why a document
 * that is not a list of transactions can be refused without a header contract to
 * catch it.
 *
 * The arithmetic and the per-row validation are not here. ImportSales owns them
 * and throws a validation error naming every row it refused, which arrives at the
 * form as ordinary field errors on `file`.
 */
class SaleImportController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Sales/Import', [
            'customers' => $this->customers(),

            // The contract, sent to the page rather than described in the markup, so
            // the column names on screen are the ones the import actually enforces
            // and a change to config/imports.php changes the instruction too. The
            // row limit travels with them for the same reason: a limit stated in
            // markup is a second number to keep in step, and it is the number a
            // person splits their document by.
            'columns' => config('imports.columns'),
            'maxRows' => (int) config('imports.max_rows'),
            'textLimit' => (int) config('imports.text_limit'),
        ]);
    }

    /**
     * The uploaded document read into a draft, with nothing written.
     *
     * Answered with a page rather than a redirect, which is what lets the draft be
     * carried to the review screen as props instead of being stored somewhere. The
     * cost of that choice is that the review screen's URL is this POST, so a reload
     * of it cannot be served at all — see refresh(), which answers that with the
     * form and a notice. The benefit is that there is no draft table, no session
     * entry, and no orphaned state to expire.
     */
    public function extract(ExtractSalesImportRequest $request, ImportSales $importSales): Response
    {
        $draft = $importSales->draft($request->file('file'));

        return Inertia::render('Sales/ImportReview', [
            'draft' => $draft['rows'],
            'origin' => $draft['origin'],
            'customers' => $this->customers(),
            'columns' => config('imports.columns'),
            'maxRows' => (int) config('imports.max_rows'),

            // The customer and the date are chosen once on the first screen and
            // carried through, because a document of rows cannot know either.
            'context' => [
                'customer_id' => (int) $request->validated('customer_id'),
                'sold_at' => $request->validated('sold_at'),
            ],
        ]);
    }

    public function store(ConfirmSalesImportRequest $request, ImportSales $importSales): RedirectResponse
    {
        $validated = $request->validated();

        $count = $importSales->handleRows(
            $this->rows($validated['rows']),
            [
                'customer_id' => (int) $validated['customer_id'],
                'sold_at' => $validated['sold_at'] ?? null,
                'origin' => $validated['origin'],
            ],
            $request->user(),
        );

        // `status` rather than `success`. The shell renders one flash, read from
        // that key, and a message written under any other name reaches the session
        // and is never shown — which is what this one used to do.
        return to_route('sales.index')
            ->with('status', "{$count} penjualan diimpor.");
    }

    /**
     * A refresh of the review screen, answered with the form and a notice.
     *
     * The review screen is the response to a POST, which means its URL is not
     * something a GET can serve. Reloading the page, bookmarking it, or stepping
     * forward to it again all arrive as a GET, and without this route the framework
     * answers 405 Method Not Allowed — a correct answer and a useless one, because
     * reloading the page you are looking at is an ordinary thing to do, and the
     * reason it cannot work here is ours to explain.
     *
     * The draft is not kept anywhere, on purpose: it exists so a person can look at
     * it, and it stops existing when they leave. That is what makes this repeatable
     * rather than lossy — nothing was written, so reading the document again costs
     * one upload and nothing else.
     */
    public function refresh(): RedirectResponse
    {
        return to_route('sales.import')->with(
            'status',
            'Halaman periksa tidak bisa dimuat ulang karena draf tidak disimpan. Baca ulang dokumennya untuk membuat draf baru — belum ada penjualan yang tersimpan.',
        );
    }

    /**
     * The posted rows numbered by their position.
     *
     * The number is assigned here rather than accepted from the request, so the
     * row a refusal names is the row the person counted on the review screen.
     * Taking it from the browser would let a draft that was reordered on the way
     * back point every message at the wrong line.
     *
     * @param  array<int, array{cells: array<string, string>}>  $rows
     * @return list<array{row: int, cells: array<string, string>}>
     */
    private function rows(array $rows): array
    {
        $numbered = [];

        foreach (array_values($rows) as $index => $row) {
            $numbered[] = ['row' => $index + 1, 'cells' => $row['cells']];
        }

        return $numbered;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function customers(): array
    {
        return Customer::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->all();
    }
}
