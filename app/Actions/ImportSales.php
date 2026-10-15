<?php

namespace App\Actions;

use App\Ai\Agents\SalesDocumentExtractor;
use App\Models\City;
use App\Models\Product;
use App\Models\Salesperson;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Turns an uploaded document into sales, one single-item sale per row.
 *
 * Two paths lead to the same rows, and the difference between them is whether
 * anything had to be inferred.
 *
 * A tabular source — a text file whose first row names the contract, or an .xlsx —
 * is read exactly: the columns are matched by name, and nothing is interpreted.
 * Anything else is handed to SalesDocumentExtractor, and what comes back is a
 * draft a person confirms before it reaches here. Either way the rows that arrive
 * are validated by the same code, so a name the catalogue does not carry is
 * refused whether it was read from a spreadsheet or guessed by a model.
 *
 * The whole draft is resolved and checked before anything is written, and the
 * writes then happen inside one transaction. Both halves matter:
 *
 * A partial import is the worst outcome available here. Sales are invoices — they
 * carry money, they are counted as revenue, and the dashboard reconciles them
 * against their items. Fifty rows imported out of two hundred is not fifty correct
 * sales, it is a document that says two hundred and a ledger that says fifty, with
 * nothing on screen to say which of the two is right. So a draft with one unknown
 * product name writes nothing and reports every problem it found, rather than
 * importing the rows it happens to understand.
 *
 * Rows are resolved against the database by name and never guessed. "Yamaha" is
 * five products in this catalogue, priced from 900 thousand to 33 million, and
 * picking one silently would write an invoice nobody agreed to. Zero matches and
 * two matches are both refused, and the second says which candidates collided.
 *
 * Grouping several rows into one order is not attempted. That is what the manual
 * sale form is for; here every row is its own sale, which is a shape worth stating
 * plainly rather than a limitation discovered after the fact.
 */
class ImportSales
{
    public function __construct(
        private readonly CreateSale $createSale,
        private readonly ReadSalesDocument $reader,
        private readonly SalesDocumentExtractor $extractor,
    ) {}

    /**
     * The document read into rows, without writing anything.
     *
     * Split from the write so the two can happen in different requests: the draft
     * is shown for review, and only a confirmed draft is handed to handleRows. The
     * origin travels with the rows because the note on each created sale says
     * where it came from, and that is the only place on the record that does.
     *
     * @return array{origin: 'table'|'document', rows: list<array{row: int, cells: array<string, string>}>}
     *
     * @throws ValidationException
     */
    public function draft(UploadedFile $file): array
    {
        $document = $this->reader->read($file);

        $table = $this->table($document);

        if ($table !== null) {
            return ['origin' => 'table', 'rows' => $table];
        }

        return ['origin' => 'document', 'rows' => $this->extracted($document['text'])];
    }

    /**
     * Confirmed rows written as sales, or one error naming every problem.
     *
     * @param  list<array{row: int, cells: array<string, string>}>  $rows
     * @param  array{customer_id: int, sold_at?: string|null, origin: string}  $context
     * @return int How many sales were created.
     *
     * @throws ValidationException
     */
    public function handleRows(array $rows, array $context, ?User $actor = null): int
    {
        $payloads = $this->resolve($rows, $context);

        return DB::transaction(function () use ($payloads, $actor): int {
            foreach ($payloads as $payload) {
                // CreateSale rather than a write here, so an imported sale is
                // identical to a keyed one: totals computed rather than accepted,
                // a reference generated, a sanitised note, and an opening status
                // event so the audit trail starts the same way.
                $this->createSale->handle($payload, $actor);
            }

            return count($payloads);
        });
    }

    /**
     * The document read as the contract table, or null when it is not one.
     *
     * A spreadsheet arrives as cells and only has to have its header checked. A
     * text file has to be split first, and which character splits it is the
     * question the delimiters list answers: the file is tried against each in turn
     * and whichever yields the contract header wins. A file that matches none is
     * not an error yet — it may be a report rather than a table, which is the other
     * path.
     *
     * @param  array{text: string, grid: list<array<int, string>>|null}  $document
     * @return list<array{row: int, cells: array<string, string>}>|null
     */
    private function table(array $document): ?array
    {
        if ($document['grid'] !== null) {
            $header = $this->columns($document['grid'][0] ?? []);

            return $header === null ? null : $this->rows($document['grid'], $header);
        }

        foreach (config('imports.delimiters') as $delimiter) {
            $grid = $this->grid($document['text'], $delimiter);

            if ($grid === []) {
                continue;
            }

            $header = $this->columns($grid[0]);

            if ($header !== null) {
                return $this->rows($grid, $header);
            }
        }

        return null;
    }

    /**
     * The document read by the model, or a refusal naming why no rows came back.
     *
     * @return list<array{row: int, cells: array<string, string>}>
     */
    private function extracted(string $text): array
    {
        try {
            $rows = $this->extractor->extract($text);
        } catch (Throwable $e) {
            // No API key, no quota, a timeout, or a retired model all end up here.
            // Reported first, because a configuration mistake and a provider
            // outage look identical from the outside and only one is a bug.
            report($e);

            throw ValidationException::withMessages([
                'file' => ['Dokumen ini tidak bisa dibaca oleh layanan AI saat ini. Coba lagi sebentar lagi, atau kirim berkas tabel (.txt atau .xlsx).'],
            ]);
        }

        // An empty draft is the expected answer for a document that is not a list
        // of transactions, so it is the message a person sees when they upload a
        // summary. It is also what a provider ignoring the schema would produce,
        // which is why the wording points at the document rather than at the model.
        if ($rows === []) {
            throw ValidationException::withMessages([
                'file' => ['Tidak ada baris penjualan yang dikenali di dokumen ini. Pastikan isinya daftar transaksi, bukan rekap atau ringkasan.'],
            ]);
        }

        $max = (int) config('imports.max_rows');

        // Refused rather than cut short at the limit. A draft silently missing its
        // last rows looks complete, and the person confirming it has no way to know
        // which of their rows are gone.
        if (count($rows) > $max) {
            throw ValidationException::withMessages([
                'file' => ['Dokumen menghasilkan '.count($rows)." baris, batas maksimum {$max}. Bagi menjadi beberapa berkas."],
            ]);
        }

        return $rows;
    }

    /**
     * The text as a grid of cells, one array per non-blank line.
     *
     * Parsed line by line rather than through a single str_getcsv over the whole
     * string, because the contract is line-oriented and the row number a message
     * quotes has to be the line the person can find in their editor.
     *
     * @return list<array<int, string>>
     */
    private function grid(string $content, string $delimiter): array
    {
        $grid = [];

        foreach (preg_split('/\r\n|\n|\r/', $content) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }

            // The fourth argument is the escape character, given as empty: PHP 8.4 began
            // warning about the default and PHP 8.5 changes it, and an empty
            // escape is also what RFC 4180 says a CSV is — a backslash in a value
            // is a backslash, not a way to escape the next character.
            $grid[] = str_getcsv($line, $delimiter, '"', '');
        }

        return $grid;
    }

    /**
     * The header read as the contract, or null when it is not the contract.
     *
     * Unknown columns are refused rather than skipped. A header that carries a
     * column this import does not read is a file the person expected to be fully
     * imported, and importing the understood part of it silently would make that
     * belief wrong in the one way that is hard to notice afterwards.
     *
     * The header's own width travels with it because a data row is compared
     * against the number of fields the header declared, which is not the same
     * number as the number of contract columns once a header has a blank in it.
     *
     * @param  array<int, string>  $header
     * @return array{map: array<string, int>, width: int}|null
     */
    private function columns(array $header): ?array
    {
        $expected = config('imports.columns');

        $map = [];

        foreach ($header as $position => $cell) {
            $name = mb_strtolower(trim($cell));

            if ($name === '') {
                continue;
            }

            if (in_array($name, $expected, true) === false || isset($map[$name])) {
                return null;
            }

            $map[$name] = $position;
        }

        foreach ($expected as $name) {
            if (isset($map[$name]) === false) {
                return null;
            }
        }

        return ['map' => $map, 'width' => count($header)];
    }

    /**
     * The data rows, keyed by contract name.
     *
     * @param  list<array<int, string>>  $grid
     * @param  array{map: array<string, int>, width: int}  $header
     * @return list<array{row: int, cells: array<string, string>}>
     */
    private function rows(array $grid, array $header): array
    {
        $columns = $header['map'];
        $width = $header['width'];

        $rows = [];
        $errors = [];

        foreach (array_slice($grid, 1) as $cells) {
            $number = count($rows) + 1;

            // A row with the wrong number of cells is nearly always an unquoted
            // delimiter inside a value — `900000,00` written bare splits the price
            // and shifts everything after it. Guessing the intended shape would be
            // inventing a number, so the row is refused and the cause is named.
            if (count($cells) !== $width) {
                $errors[] = 'Baris '.$number.': punya '.count($cells).' kolom, header mendeklarasikan '
                    .$width.'. Jika nilai mengandung koma atau titik koma, bungkus dengan tanda kutip.';

                continue;
            }

            $names = array_keys($columns);

            $rows[] = [
                'row' => $number,

                // Keyed by contract name, because resolve() reads the cells by
                // name. array_combine rather than array_map: array_map given a
                // single array returns a list and discards the keys it was handed,
                // so the cells would arrive positional and every named lookup
                // below would quietly find nothing.
                'cells' => array_combine($names, array_map(
                    fn (string $name): string => trim((string) $cells[$columns[$name]]),
                    $names,
                )),
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['file' => $errors]);
        }

        if ($rows === []) {
            throw ValidationException::withMessages([
                'file' => ['Berkas hanya berisi header, tidak ada baris data.'],
            ]);
        }

        $max = (int) config('imports.max_rows');

        if (count($rows) > $max) {
            throw ValidationException::withMessages([
                'file' => ['Berkas berisi '.count($rows)." baris, batas maksimum {$max}. Bagi menjadi beberapa berkas."],
            ]);
        }

        return $rows;
    }

    /**
     * Every row turned into a CreateSale payload, or one error naming every problem.
     *
     * All three directories are read once each rather than queried per row: a draft
     * of two hundred rows against three lookups each is six hundred queries for
     * data that changes not at all during the request.
     *
     * @param  list<array{row: int, cells: array<string, string>}>  $rows
     * @param  array{customer_id: int, sold_at?: string|null, origin: string}  $context
     * @return list<array<string, mixed>>
     */
    private function resolve(array $rows, array $context): array
    {
        $people = $this->directory(Salesperson::query()->pluck('name', 'id')->all());
        $cities = $this->directory(City::query()->pluck('name', 'id')->all());
        $products = $this->directory(Product::query()->pluck('name', 'id')->all());

        $payloads = [];
        $errors = [];

        foreach ($rows as $row) {
            $prefix = "Baris {$row['row']}: ";
            $cells = $row['cells'];

            $salesperson = $this->lookup($people, $cells['sales'] ?? '');
            $city = $this->lookup($cities, $cells['kota'] ?? '');
            $product = $this->lookup($products, $cells['produk'] ?? '');

            $quantity = $this->quantity($cells['jumlah'] ?? '');
            $price = $this->price($cells['harga'] ?? '');

            foreach ([$salesperson, $city, $product] as $matched) {
                if (is_string($matched)) {
                    $errors[] = $prefix.$matched;
                }
            }

            if (is_string($quantity)) {
                $errors[] = $prefix.$quantity;
            }

            if (is_string($price)) {
                $errors[] = $prefix.$price;
            }

            if (is_string($salesperson) || is_string($city) || is_string($product)
                || is_string($quantity) || is_string($price)) {
                continue;
            }

            $payloads[] = [
                'customer_id' => (int) $context['customer_id'],
                'salesperson_id' => $salesperson,
                'city_id' => $city,
                'sold_at' => $context['sold_at'] ?? null,

                // Provenance. A bulk write is the one case where a sale exists
                // without anyone having typed it, and the note is the only place
                // on the record that says so — the status history says only that
                // it was created. Which of the two paths read the document is
                // worth recording, because one of them involved a model.
                'note' => $this->note($context['origin']),

                'items' => [[
                    'product_id' => $product,
                    'quantity' => $quantity,
                    'unit_price' => $price,
                ]],
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['file' => $errors]);
        }

        return $payloads;
    }

    /**
     * The note an imported sale carries.
     */
    private function note(string $origin): string
    {
        return $origin === 'document'
            ? 'Diimpor dari dokumen (draf AI).'
            : 'Diimpor dari berkas tabel.';
    }

    /**
     * Names keyed by their normalised form, each holding every row with that name.
     *
     * Case-insensitive and trimmed because that is the difference between a file
     * typed by a person and a file exported by a machine, and an import that fails
     * on "budi" versus "Budi" teaches nothing the person can act on.
     *
     * A list per name rather than one id per name, because the duplicate is the
     * case that must be refused rather than resolved.
     *
     * @param  array<int, string>  $names
     * @return array<string, list<array{id: int, name: string}>>
     */
    private function directory(array $names): array
    {
        $directory = [];

        foreach ($names as $id => $name) {
            $directory[mb_strtolower(trim($name))][] = ['id' => (int) $id, 'name' => $name];
        }

        return $directory;
    }

    /**
     * The single id a cell names, or a sentence explaining why it does not name one.
     *
     * @param  array<string, list<array{id: int, name: string}>>  $directory
     * @return int|string
     */
    private function lookup(array $directory, string $value)
    {
        if ($value === '') {
            return 'nilai kosong.';
        }

        $matches = $directory[mb_strtolower($value)] ?? [];

        if ($matches === []) {
            return '"'.$value.'" tidak ada di daftar.';
        }

        if (count($matches) > 1) {
            $names = array_map(fn (array $match): string => $match['name'], $matches);

            return '"'.$value.'" cocok dengan lebih dari satu: '.implode(', ', $names)
                .'. Gunakan nama yang lebih spesifik.';
        }

        return $matches[0]['id'];
    }

    /**
     * The quantity, or a sentence. Digits only: no separators, no decimal point.
     *
     * @return int|string
     */
    private function quantity(string $value)
    {
        if ($value === '') {
            return 'jumlah kosong.';
        }

        if ($this->isThousands($value)) {
            return 'jumlah "'.$value.'" memakai pemisah ribuan. Tulis '.str_replace('.', '', $value).'.';
        }

        if (preg_match('/^\d+$/', $value) !== 1) {
            return 'jumlah "'.$value.'" bukan bilangan bulat.';
        }

        $quantity = (int) $value;

        $max = (int) config('imports.max_quantity');

        if ($quantity < 1 || $quantity > $max) {
            return "jumlah \"{$value}\" di luar 1–{$max}.";
        }

        return $quantity;
    }

    /**
     * The price, or a sentence.
     *
     * A float rather than the string as written, which is what the id-returning
     * pair above already does and what makes `is_string()` an honest way to ask
     * "did this fail". It costs nothing in precision: CreateSale rounds the
     * amount to two decimals as a float at its own boundary, so a float handed
     * over here is rounded exactly once and in the same place as every other
     * price in the application.
     *
     * @return float|string
     */
    private function price(string $value)
    {
        if ($value === '') {
            return 'harga kosong.';
        }

        if ($this->isThousands($value)) {
            return 'harga "'.$value.'" memakai pemisah ribuan. Tulis '.str_replace('.', '', $value).'.';
        }

        // A comma is accepted as the decimal separator because a file written in
        // Indonesian convention writes 15000,50, and with a semicolon delimiter
        // that comma is unambiguous. With a comma delimiter the value would have
        // been split into two cells and rejected on column count instead.
        if (preg_match('/^\d+([.,]\d{1,2})?$/', $value) !== 1) {
            return 'harga "'.$value.'" bukan angka. Tulis seperti 15000 atau 15000.50.';
        }

        return (float) str_replace(',', '.', $value);
    }

    /**
     * Whether the digits are grouped the Indonesian way: 1.500, 90.000.000.
     *
     * Recognised in order to be refused by name. Left as a silent parse error it
     * produces "harga bukan angka", which is true and useless: the value looks
     * like a perfectly good number to the person who typed it, and the rule they
     * are actually breaking is that the file has no thousand separators.
     */
    private function isThousands(string $value): bool
    {
        return preg_match('/^\d{1,3}(\.\d{3})+$/', $value) === 1;
    }
}
