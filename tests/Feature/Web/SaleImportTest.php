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
use Tests\TestCase;

/**
 * Importing sales from a document that is a table.
 *
 * Two things are being held apart here. The extraction writes nothing at all, and
 * the confirmation writes everything or nothing — so most of these tests assert
 * both the message and that the sales table is still empty, because an import that
 * half-succeeds is the outcome they exist to prevent.
 *
 * The document path is in SaleImportDocumentTest. Everything the two have in
 * common is the validation, which lives in ImportSales and is exercised here,
 * because a table is the cheaper way to reach it.
 */
class SaleImportTest extends TestCase
{
    use RefreshDatabase;

    private ?Customer $customer = null;

    private function customer(): Customer
    {
        return $this->customer ??= Customer::factory()->create(['name' => 'Toko Aman']);
    }

    /**
     * A file as the browser would deliver it.
     *
     * Real bytes in a real temporary file, with the type left to be sniffed, for
     * the reason UploadTest gives: a fake reports whatever it was told and never
     * looks at the content, so a test built on it would pass whether or not the
     * server inspected the file at all.
     */
    private function document(string $contents, string $name = 'laporan.txt'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'sale-import-test');
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function header(): string
    {
        return implode(',', config('imports.columns'));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(UploadedFile $file, array $overrides = []): array
    {
        return array_merge([
            'file' => $file,
            'customer_id' => $this->customer()->id,
            'sold_at' => '2026-03-01',
        ], $overrides);
    }

    /**
     * The rows the review screen was handed.
     *
     * @return list<array{row: int, cells: array<string, string>}>
     */
    private function draft(TestResponse $response): array
    {
        return $response->inertiaProps()['draft'];
    }

    /**
     * The draft posted back as a person would from the review screen.
     *
     * @param  list<array{row: int, cells: array<string, string>}>  $draft
     */
    private function confirm(array $draft, array $overrides = []): TestResponse
    {
        return $this->post('/sales/import', array_merge([
            'origin' => 'table',
            'customer_id' => $this->customer()->id,
            'sold_at' => '2026-03-01',

            // The row number is not sent: the server assigns it from the position,
            // which is what keeps a refusal pointing at the row on screen.
            'rows' => array_map(fn (array $row): array => ['cells' => $row['cells']], $draft),
        ], $overrides));
    }

    public function test_the_form_states_the_contract_the_import_enforces(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/sales/import')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Sales/Import')
                ->where('columns', config('imports.columns'))
                ->where('maxRows', config('imports.max_rows'))
                ->where('textLimit', config('imports.text_limit'))
                ->has('customers')
            );
    }

    public function test_a_table_is_read_into_a_draft_and_writes_nothing(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Salesperson::factory()->create(['name' => 'Andi']);
        City::factory()->create(['name' => 'Jakarta']);
        Product::factory()->create(['name' => 'Helm ORIGINAL']);

        $response = $this->post('/sales/import/extract', $this->payload($this->document(
            $this->header()."\n".'Andi,Jakarta,Helm ORIGINAL,2,15000'
        )));

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->component('Sales/ImportReview')
            ->where('origin', 'table')
        );

        $this->assertSame([
            'sales' => 'Andi', 'kota' => 'Jakarta', 'produk' => 'Helm ORIGINAL', 'jumlah' => '2', 'harga' => '15000',
        ], $this->draft($response)[0]['cells']);

        // The whole point of the review step: reading a document is not writing
        // one, and the sales table does not move until the draft is confirmed.
        $this->assertSame(0, Sale::count());

        // And the model was not consulted, because the table already said what it
        // meant. This is the guarantee that the table path stayed deterministic.
        SalesDocumentExtractor::assertNeverPrompted();
    }

    public function test_a_confirmed_draft_becomes_one_sale_per_row(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $salesperson = Salesperson::factory()->create(['name' => 'Andi']);
        $city = City::factory()->create(['name' => 'Jakarta']);
        $first = Product::factory()->create(['name' => 'Helm ORIGINAL']);
        $second = Product::factory()->create(['name' => 'Kaca motorcycle']);

        $draft = $this->draft($this->post('/sales/import/extract', $this->payload($this->document(
            $this->header()."\n"
            ."Andi,Jakarta,Helm ORIGINAL,2,15000\n"
            .'Andi,Jakarta,Kaca motorcycle,1,25000'
        ))));

        $response = $this->confirm($draft);

        $response->assertRedirect(route('sales.index'));
        $response->assertSessionHas('status');

        $this->assertSame(2, Sale::count());

        $helmet = Sale::whereHas('items', fn ($items) => $items->where('product_id', $first->id))->sole();

        // Two sales, not one order of two lines: grouping rows is the manual
        // form's job, and this test is what keeps that from drifting into a
        // silent second meaning.
        $this->assertSame($salesperson->id, $helmet->salesperson_id);
        $this->assertSame($city->id, $helmet->city_id);
        $this->assertCount(1, $helmet->items);
        $this->assertSame(2, $helmet->items->sole()->quantity);
        $this->assertSame(30000.0, (float) $helmet->grand_total);
        $this->assertSame('pending', $helmet->status->value);

        // The note is the only place on the record that says the sale was not
        // typed by anyone, so which path read the document is recorded there.
        $this->assertSame('Diimpor dari berkas tabel.', $helmet->note);

        // Every imported sale is a real sale, so it carries the same opening
        // history entry a keyed one does.
        $this->assertCount(1, $helmet->statusEvents);
        $this->assertTrue(
            Sale::whereHas('items', fn ($items) => $items->where('product_id', $second->id))->exists()
        );
    }

    public function test_refreshing_the_review_screen_warns_instead_of_failing(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        // The review screen is the answer to a POST, so its URL is what the browser
        // is left showing. Reloading it is ordinary, and it arrives as a GET: before
        // this route existed the framework answered 405 with a stack trace, which
        // told the person nothing about the draft they thought they still had.
        $response = $this->get('/sales/import/extract');

        $response->assertRedirect(route('sales.import'));

        $this->assertStringContainsString('dimuat ulang', (string) session('status'));

        // And nothing was written on either side of it.
        $this->assertSame(0, Sale::count());
    }

    public function test_a_draft_with_a_missing_cell_is_refused_at_the_door(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        // Checked by the request rather than by ImportSales: a row that is the
        // wrong shape never reaches the code that reads cells by name, which would
        // otherwise report a missing value without saying the row was malformed.
        $this->confirm([], [
            'rows' => [['cells' => ['sales' => 'Andi']]],
        ])->assertSessionHasErrors('rows.0.cells.produk');

        $this->assertSame(0, Sale::count());
    }

    public function test_an_empty_cell_is_refused_by_row_rather_than_by_shape(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Salesperson::factory()->create(['name' => 'Andi']);
        City::factory()->create(['name' => 'Jakarta']);

        // The product column left blank, which is what the document path produces
        // when the file does not carry a value there. The request lets the empty
        // string through so the refusal can name the row and the value — the
        // message a person can act on. Refusing it as a missing field would only
        // say that something was required.
        $draft = $this->draft($this->post('/sales/import/extract', $this->payload($this->document(
            $this->header()."\n".'Andi,Jakarta,,1,15000'
        ))));

        $this->confirm($draft)->assertSessionHasErrors('file');

        $error = session('errors')->first('file');

        $this->assertStringContainsString('Baris 1', $error);
        $this->assertStringContainsString('nilai kosong', $error);
        $this->assertSame(0, Sale::count());
    }

    public function test_names_are_matched_without_regard_to_case_or_padding(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Salesperson::factory()->create(['name' => 'Budi Santoso']);
        City::factory()->create(['name' => 'Bandung']);
        Product::factory()->create(['name' => 'Yamaha Nmax 155']);

        $draft = $this->draft($this->post('/sales/import/extract', $this->payload($this->document(
            $this->header()."\n".'  budi santoso , bandung , yamaha nmAX 155,1,33000000'
        ))));

        $this->confirm($draft)->assertSessionHasNoErrors();

        $this->assertSame(1, Sale::count());
    }

    public function test_a_semicolon_document_with_a_byte_order_mark_is_read(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Salesperson::factory()->create(['name' => 'Andi']);
        City::factory()->create(['name' => 'Jakarta']);
        Product::factory()->create(['name' => 'Helm']);

        // The BOM is Excel's, not ours: invisible in the file, and fatal to a
        // header match if left on the first column name.
        $draft = $this->draft($this->post('/sales/import/extract', $this->payload($this->document(
            "\xEF\xBB\xBF".str_replace(',', ';', $this->header())."\n".'Andi;Jakarta;Helm;1;15000'
        ))));

        $this->confirm($draft)->assertSessionHasNoErrors();

        $this->assertSame(1, Sale::count());
    }

    public function test_a_quoted_value_containing_the_delimiter_keeps_its_value(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Salesperson::factory()->create(['name' => 'Andi, S.E.']);
        City::factory()->create(['name' => 'Jakarta']);
        Product::factory()->create(['name' => 'Helm']);

        $draft = $this->draft($this->post('/sales/import/extract', $this->payload($this->document(
            $this->header()."\n".'"Andi, S.E.",Jakarta,Helm,1,15000'
        ))));

        $this->confirm($draft)->assertSessionHasNoErrors();

        $this->assertSame('Andi, S.E.', Sale::sole()->salesperson->name);
    }

    public function test_an_unknown_name_refuses_the_whole_draft(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Salesperson::factory()->create(['name' => 'Andi']);
        City::factory()->create(['name' => 'Jakarta']);
        Product::factory()->create(['name' => 'Helm ORIGINAL']);

        $draft = $this->draft($this->post('/sales/import/extract', $this->payload($this->document(
            $this->header()."\n"
            ."Andi,Jakarta,Helm ORIGINAL,1,15000\n"
            .'Andi,Jakarta,Helem,1,15000'
        ))));

        $response = $this->confirm($draft);

        $response->assertSessionHasErrors('file');

        // The row that was perfectly good is not imported either.
        $this->assertSame(0, Sale::count());

        $this->assertStringContainsString(
            '"Helem" tidak ada di daftar',
            session('errors')->first('file')
        );
    }

    public function test_every_refused_row_is_reported_in_one_pass(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Salesperson::factory()->create(['name' => 'Andi']);
        City::factory()->create(['name' => 'Jakarta']);
        Product::factory()->create(['name' => 'Helm']);

        // Two different faults on two different rows. The point of checking the
        // whole draft before writing any of it is that both are found now, rather
        // than after the document is corrected and uploaded again.
        $draft = $this->draft($this->post('/sales/import/extract', $this->payload($this->document(
            $this->header()."\n"
            .'Andi,Jakarta,Helem,1,15000'."\n"
            .'Andi,Jakarta,Helm,0,15000'
        ))));

        $this->confirm($draft)->assertSessionHasErrors('file');

        $errors = session('errors')->get('file');

        $this->assertCount(2, $errors);
        $this->assertStringContainsString('Baris 1', $errors[0]);
        $this->assertStringContainsString('Baris 2', $errors[1]);
        $this->assertSame(0, Sale::count());
    }

    public function test_an_ambiguous_name_refuses_the_draft_and_names_the_candidates(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Salesperson::factory()->create(['name' => 'Andi']);
        City::factory()->create(['name' => 'Jakarta']);

        // Two catalogue rows under one name, which is what a duplicate import of a
        // catalogue leaves behind. Picking one silently would write an invoice
        // nobody agreed to, so the draft is refused and both names are quoted.
        Product::factory()->create(['name' => 'Yamaha', 'price' => 5000000]);
        Product::factory()->create(['name' => 'Yamaha', 'price' => 33000000]);

        $draft = $this->draft($this->post('/sales/import/extract', $this->payload($this->document(
            $this->header()."\n".'Andi,Jakarta,Yamaha,1,33000000'
        ))));

        $this->confirm($draft)->assertSessionHasErrors('file');

        $error = session('errors')->first('file');

        $this->assertStringContainsString('lebih dari satu', $error);

        // Both candidates are listed, and the message says what to do about it,
        // so the person can go and rename one of them rather than guess.
        $this->assertStringContainsString('Yamaha, Yamaha.', $error);
        $this->assertStringContainsString('Gunakan nama yang lebih spesifik', $error);
        $this->assertSame(0, Sale::count());
    }

    public function test_a_thousands_separator_is_refused_by_name(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Salesperson::factory()->create(['name' => 'Andi']);
        City::factory()->create(['name' => 'Jakarta']);
        Product::factory()->create(['name' => 'Helm']);

        $draft = $this->draft($this->post('/sales/import/extract', $this->payload($this->document(
            $this->header()."\n".'Andi,Jakarta,Helm,1,15.000'
        ))));

        $this->confirm($draft)->assertSessionHasErrors('file');

        $error = session('errors')->first('file');

        $this->assertStringContainsString('pemisah ribuan', $error);
        // The fix is in the message: the person has to be told what to write.
        $this->assertStringContainsString('15000', $error);
        $this->assertSame(0, Sale::count());
    }

    public function test_a_decimal_comma_is_accepted_in_a_semicolon_document(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Salesperson::factory()->create(['name' => 'Andi']);
        City::factory()->create(['name' => 'Jakarta']);
        Product::factory()->create(['name' => 'Helm']);

        $draft = $this->draft($this->post('/sales/import/extract', $this->payload($this->document(
            str_replace(',', ';', $this->header())."\n".'Andi;Jakarta;Helm;1;15000,50'
        ))));

        $this->confirm($draft)->assertSessionHasNoErrors();

        // One unit at 15000.50, so the comma survives into the stored total.
        $this->assertSame(15000.5, (float) Sale::sole()->grand_total);
    }

    public function test_a_quantity_outside_the_bounds_is_refused(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Salesperson::factory()->create(['name' => 'Andi']);
        City::factory()->create(['name' => 'Jakarta']);
        Product::factory()->create(['name' => 'Helm']);

        $draft = $this->draft($this->post('/sales/import/extract', $this->payload($this->document(
            $this->header()."\n".'Andi,Jakarta,Helm,0,15000'
        ))));

        $this->confirm($draft)->assertSessionHasErrors('file');

        $this->assertStringContainsString('di luar 1', session('errors')->first('file'));
        $this->assertSame(0, Sale::count());
    }

    public function test_a_row_with_the_wrong_number_of_columns_is_refused(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Salesperson::factory()->create(['name' => 'Andi']);
        City::factory()->create(['name' => 'Jakarta']);
        Product::factory()->create(['name' => 'Helm']);

        // An unquoted comma inside the price: the row splits and everything after
        // the split would be read as a column. Refused while reading, so it never
        // reaches the review screen at all.
        $this->post('/sales/import/extract', $this->payload($this->document(
            $this->header()."\n".'Andi,Jakarta,Helm,1,15000,50'
        )))->assertSessionHasErrors('file');

        $this->assertStringContainsString('tanda kutip', session('errors')->first('file'));
        $this->assertSame(0, Sale::count());
    }

    public function test_a_document_over_the_row_limit_is_refused_before_anything_is_written(): void
    {
        config()->set('imports.max_rows', 2);

        $this->actingAs(User::factory()->admin()->create());

        Salesperson::factory()->create(['name' => 'Andi']);
        City::factory()->create(['name' => 'Jakarta']);
        Product::factory()->create(['name' => 'Helm']);

        $rows = str_repeat("Andi,Jakarta,Helm,1,15000\n", 3);

        $this->post('/sales/import/extract', $this->payload($this->document($this->header()."\n".$rows)))
            ->assertSessionHasErrors('file');

        $this->assertStringContainsString('batas maksimum 2', session('errors')->first('file'));
        $this->assertSame(0, Sale::count());
    }

    public function test_a_document_that_is_not_utf8_is_refused(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        // The name carries a byte that ISO-8859-1 and UTF-8 do not share, which
        // is what makes the encoding check mean anything: an all-ASCII file
        // converted between the two is byte-identical and would pass.
        $latin1 = mb_convert_encoding(
            $this->header()."\n".'André,Jakarta,Helm,1,15000',
            'ISO-8859-1',
            'UTF-8'
        );

        $this->post('/sales/import/extract', $this->payload($this->document($latin1)))
            ->assertSessionHasErrors('file');

        $this->assertStringContainsString('UTF-8', session('errors')->first('file'));
        $this->assertSame(0, Sale::count());
    }

    public function test_a_missing_file_is_refused(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->post('/sales/import/extract', [
            'customer_id' => $this->customer()->id,
            'sold_at' => '2026-03-01',
        ])->assertSessionHasErrors('file');

        $this->assertSame(0, Sale::count());
    }

    public function test_the_import_is_closed_to_ordinary_accounts(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->get('/sales/import')
            ->assertForbidden();

        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->post('/sales/import/extract', $this->payload($this->document($this->header())))
            ->assertForbidden();

        // The refresh route is a second way in to the same screen, so it sits behind
        // the same wall rather than becoming a way around it.
        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->get('/sales/import/extract')
            ->assertForbidden();

        $this->assertSame(0, Sale::count());
    }

    public function test_a_signed_out_visitor_cannot_reach_the_import(): void
    {
        $this->get('/sales/import')->assertRedirect(route('login'));
        $this->get('/sales/import/extract')->assertRedirect(route('login'));
    }
}
