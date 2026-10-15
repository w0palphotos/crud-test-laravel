<?php

namespace Tests\Feature\Web;

use App\Ai\Agents\SalesDocumentExtractor;
use App\Models\City;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Salesperson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Ai;
use Laravel\Ai\Exceptions\AiException;
use Tests\TestCase;
use ZipArchive;

/**
 * Importing sales from a document that is not a table.
 *
 * The model is faked throughout, both because the suite must not spend money or
 * wait on a network and because the behaviour under test is the application's, not
 * the provider's: what happens when an answer comes back, when an empty one does,
 * and when the call fails outright.
 *
 * The assertion that matters most here is the one about what the model is *not*
 * asked. Removing CSV removed the header contract that used to refuse a summary,
 * so a document that is not a list of transactions can only be caught by the model
 * saying so or by a person reading the draft — and both of those are only true if
 * the document actually reached them.
 */
class SaleImportDocumentTest extends TestCase
{
    use RefreshDatabase;

    private const WORD_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private function customer(): Customer
    {
        return Customer::factory()->create(['name' => 'Toko Aman']);
    }

    /**
     * @param  list<array<string, string>>  $rows
     */
    private function fakeModel(array $rows): void
    {
        // An array, not a JSON string: FakeTextGateway turns an array into the
        // structured response an agent with an output schema expects, and a string
        // into plain text the agent would have to fail to parse.
        Ai::fakeAgent(SalesDocumentExtractor::class, fn (): array => ['rows' => $rows]);
    }

    /**
     * A .docx as the browser would deliver it: a ZIP with paragraphs in it.
     */
    private function docx(string $body): UploadedFile
    {
        $namespace = self::WORD_NS;

        $path = tempnam(sys_get_temp_dir(), 'sale-import-document-test');

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString(
            'word/document.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            ."<w:document xmlns:w=\"{$namespace}\"><w:body>{$body}</w:body></w:document>",
        );
        $zip->close();

        return new UploadedFile($path, 'laporan.docx', null, null, true);
    }

    private function document(string $contents, string $name = 'laporan.txt'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'sale-import-document-test');
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, null, null, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(UploadedFile $file): array
    {
        return [
            'file' => $file,
            'customer_id' => $this->customer()->id,
            'sold_at' => '2026-03-01',
        ];
    }

    /**
     * @return list<array{row: int, cells: array<string, string>}>
     */
    private function draft(TestResponse $response): array
    {
        return $response->inertiaProps()['draft'];
    }

    public function test_a_word_document_is_read_by_the_model_into_a_draft(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->fakeModel([
            ['sales' => 'Andi', 'kota' => 'Jakarta', 'produk' => 'Helm ORIGINAL', 'jumlah' => '2', 'harga' => '15000'],
        ]);

        $response = $this->post('/sales/import/extract', $this->payload($this->docx(
            '<w:p><w:r><w:t>Laporan Penjualan</w:t></w:r></w:p>'
            .'<w:p><w:r><w:t>Andi Jakarta Helm ORIGINAL 2 15000</w:t></w:r></w:p>'
        )));

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->component('Sales/ImportReview')
            ->where('origin', 'document')
        );

        $this->assertSame([
            'sales' => 'Andi',
            'kota' => 'Jakarta',
            'produk' => 'Helm ORIGINAL',
            'jumlah' => '2',
            'harga' => '15000',
        ], $this->draft($response)[0]['cells']);

        // A draft is not a write, and the model is asked exactly once per
        // document: a retry loop here would be billed without being asked for.
        $this->assertSame(0, Sale::count());
        SalesDocumentExtractor::assertPromptedTimes(1);
    }

    public function test_the_draft_numbering_is_the_models_row_order(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->fakeModel([
            ['sales' => 'Andi', 'kota' => 'Jakarta', 'produk' => 'Helm', 'jumlah' => '1', 'harga' => '15000'],
            ['sales' => 'Budi', 'kota' => 'Bandung', 'produk' => 'Kaca', 'jumlah' => '2', 'harga' => '25000'],
        ]);

        $draft = $this->draft($this->post('/sales/import/extract', $this->payload($this->docx(
            '<w:p><w:r><w:t>dua baris</w:t></w:r></w:p>'
        ))));

        // Numbered by position rather than by what the model said, so a refusal
        // names the row a person counted on the screen.
        $this->assertSame([1, 2], array_column($draft, 'row'));
    }

    public function test_a_confirmed_model_draft_is_written_and_says_it_came_from_a_document(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Salesperson::factory()->create(['name' => 'Andi']);
        City::factory()->create(['name' => 'Jakarta']);
        Product::factory()->create(['name' => 'Helm ORIGINAL']);

        $this->fakeModel([
            ['sales' => 'Andi', 'kota' => 'Jakarta', 'produk' => 'Helm ORIGINAL', 'jumlah' => '2', 'harga' => '15000'],
        ]);

        $draft = $this->draft($this->post('/sales/import/extract', $this->payload($this->docx(
            '<w:p><w:r><w:t>Andi Jakarta Helm ORIGINAL 2 15000</w:t></w:r></w:p>'
        ))));

        $this->post('/sales/import', [
            // 'document' rather than 'table', because that is what the note on the
            // created sale records and it is the only mark on the sale saying a
            // model was involved.
            'origin' => 'document',
            'customer_id' => $this->customer()->id,
            'sold_at' => '2026-03-01',
            'rows' => array_map(fn (array $row): array => ['cells' => $row['cells']], $draft),
        ])->assertRedirect(route('sales.index'));

        $sale = Sale::sole();

        $this->assertSame('Diimpor dari dokumen (draf AI).', $sale->note);
        $this->assertSame(30000.0, (float) $sale->grand_total);
    }

    public function test_a_document_that_is_not_a_transaction_list_is_refused(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        // What the model answers for an aggregate report: nothing. The refusal the
        // person reads is then about the document, because that is the thing they
        // can change — the alternative was a row per summary line, with an average
        // filed as a unit price.
        $this->fakeModel([]);

        $response = $this->post('/sales/import/extract', $this->payload($this->document(
            "Nama Sales,Kota,Produk,Rata-rata Penjualan,Total Penjualan,Jumlah Transaksi\n"
            .'Andi,Bandung,Yamaha,90000000,90000000,1'
        )));

        $response->assertSessionHasErrors('file');

        $this->assertStringContainsString(
            'Tidak ada baris penjualan yang dikenali',
            session('errors')->first('file')
        );

        $this->assertSame(0, Sale::count());
    }

    public function test_a_model_failure_is_reported_rather_than_crashing(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        // No key, no quota, a timeout, or a retired model all land here, and none
        // of them are something the person can fix, so it must not surface as a
        // 500 with a stack trace.
        Ai::fakeAgent(SalesDocumentExtractor::class, function (): never {
            throw new AiException('quota exceeded');
        });

        $response = $this->post('/sales/import/extract', $this->payload($this->docx(
            '<w:p><w:r><w:t>laporan</w:t></w:r></w:p>'
        )));

        $response->assertSessionHasErrors('file');

        $this->assertStringContainsString('layanan AI', session('errors')->first('file'));
        $this->assertSame(0, Sale::count());
    }

    public function test_an_answer_over_the_row_limit_is_refused(): void
    {
        config()->set('imports.max_rows', 2);

        $this->actingAs(User::factory()->admin()->create());

        // The row cap is also the ceiling on what the model may hand back: a
        // document that provokes a long answer must not become a long import.
        $this->fakeModel([
            ['sales' => 'Andi', 'kota' => 'Jakarta', 'produk' => 'Helm', 'jumlah' => '1', 'harga' => '15000'],
            ['sales' => 'Andi', 'kota' => 'Jakarta', 'produk' => 'Helm', 'jumlah' => '1', 'harga' => '15000'],
            ['sales' => 'Andi', 'kota' => 'Jakarta', 'produk' => 'Helm', 'jumlah' => '1', 'harga' => '15000'],
        ]);

        $this->post('/sales/import/extract', $this->payload($this->docx(
            '<w:p><w:r><w:t>tiga baris</w:t></w:r></w:p>'
        )))->assertSessionHasErrors('file');

        $this->assertStringContainsString('batas maksimum 2', session('errors')->first('file'));
        $this->assertSame(0, Sale::count());
    }

    public function test_a_document_the_reader_cannot_open_never_reaches_the_model(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        // Binary that is not a document this application reads. The model is not
        // consulted for it — paying for a guess about bytes that were never text is
        // the failure this guards.
        $this->post('/sales/import/extract', $this->payload($this->document(
            "bukan dokumen\0ini",
            'catatan.txt'
        )))->assertSessionHasErrors('file');

        SalesDocumentExtractor::assertNeverPrompted();

        $this->assertSame(0, Sale::count());
    }

    public function test_a_model_draft_is_still_validated_against_the_catalogue(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Salesperson::factory()->create(['name' => 'Andi']);
        City::factory()->create(['name' => 'Jakarta']);
        Product::factory()->create(['name' => 'Helm ORIGINAL']);

        // A product the catalogue does not carry, which is what a model reading a
        // messy document produces. It is refused by the same code that refuses a
        // table, so the model cannot write a name that was never there.
        $this->fakeModel([
            ['sales' => 'Andi', 'kota' => 'Jakarta', 'produk' => 'Helm Sapi', 'jumlah' => '1', 'harga' => '150000'],
        ]);

        $draft = $this->draft($this->post('/sales/import/extract', $this->payload($this->docx(
            '<w:p><w:r><w:t>Andi Jakarta Helm Sapi 1 150000</w:t></w:r></w:p>'
        ))));

        $this->post('/sales/import', [
            'origin' => 'document',
            'customer_id' => $this->customer()->id,
            'sold_at' => '2026-03-01',
            'rows' => array_map(fn (array $row): array => ['cells' => $row['cells']], $draft),
        ])->assertSessionHasErrors('file');

        $this->assertStringContainsString('"Helm Sapi" tidak ada di daftar', session('errors')->first('file'));
        $this->assertSame(0, Sale::count());
    }
}
