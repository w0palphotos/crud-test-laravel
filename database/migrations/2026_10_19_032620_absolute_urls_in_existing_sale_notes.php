<?php

use App\Actions\SanitiseRichText;
use App\Models\Sale;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Give the links already stored in sale notes the scheme they were written
     * without.
     *
     * A note typed as `<a href="www.google.com">` stores a path, not a link, and
     * the browser resolves it against whichever page is showing it — so on
     * /sales/12 it leads to /sales/www.google.com. SanitiseRichText now rewrites
     * these as they are written; this is the same repair for the ones written
     * before it, and it is the only way to reach them: a stored note is not
     * re-sanitised on the way out, because doing that on every read is not
     * lossless and would cost every viewer the millisecond it saves the reader.
     *
     * Only the URL normalisation runs here. Re-purifying rows that are already
     * clean would rewrite markup the current allow-list no longer agrees with,
     * for no gain: the purifier is for content arriving from outside, and this
     * content is not arriving from anywhere.
     */
    public function up(): void
    {
        $sanitise = app(SanitiseRichText::class);

        Sale::query()
            ->select(['id', 'note'])
            ->whereNotNull('note')
            ->where('note', '!=', '')
            ->chunkById(500, function ($sales) use ($sanitise): void {
                foreach ($sales as $sale) {
                    $absolute = $sanitise->absoluteUrls($sale->note);

                    if ($absolute !== $sale->note) {
                        DB::table('sales')->where('id', $sale->id)->update(['note' => $absolute]);
                    }
                }
            });
    }

    /**
     * There is nothing to undo.
     *
     * The bare host and the absolute URL that replaced it are the same link, and
     * once the scheme is on there is no way to tell which rows were rewritten.
     * `down()` re-running the normalisation is a no-op by design.
     */
    public function down(): void
    {
        //
    }
};
