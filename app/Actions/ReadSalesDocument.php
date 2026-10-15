<?php

namespace App\Actions;

use DOMDocument;
use DOMNode;
use DOMXPath;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Smalot\PdfParser\Parser;
use Throwable;
use ZipArchive;

/**
 * Turns an uploaded file into either text or a table of cells.
 *
 * This is where the format is actually decided, and it decides it from the bytes.
 * config/imports.php carries a wide MIME list and cannot do better than that: the
 * same spreadsheet arrives as application/zip or as the long
 * openxmlformats... type depending on the browser that picked it, and every .docx
 * is a ZIP before it is anything else. So the type list only has to be wide enough
 * not to refuse a correct file, and the inspection here is the gate.
 *
 * Two shapes come out, and the caller keeps them apart:
 *
 *   text  Always present. It is what the model reads, and for a plain text file it
 *         is also what the delimiter parser works on.
 *   grid  Present only for a source that is tabular by construction — an .xlsx,
 *         whose cells arrive already separated. A delimiter parser over a
 *         spreadsheet's text would be a guess about where one cell ends, and a
 *         value may legitimately contain the comma it guessed on.
 *
 * A .docx is a ZIP of XML with paragraphs, and a PDF is a stream of positioned
 * glyphs. Neither has rows, so neither gets a grid: they are read as text and
 * handed to the model, whose draft a person then checks.
 */
class ReadSalesDocument
{
    /**
     * The spreadsheet namespace, used by both .xlsx parts.
     */
    private const SPREADSHEET_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    /**
     * The wordprocessing namespace, used by word/document.xml.
     */
    private const WORD_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /**
     * OLE2 compound file header: a .doc, .xls, .ppt or .msi.
     *
     * Recognised in order to be refused by name. These are read by a different,
     * much larger parser, and handing the bytes to the Office XML reader would
     * fail with something that says nothing about the fix. The fix is to save the
     * file again in the modern format, and the message says so.
     */
    private const OLE_MAGIC = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

    /**
     * @return array{text: string, grid: list<array<int, string>>|null}
     *
     * @throws ValidationException
     */
    public function read(UploadedFile $file): array
    {
        $document = $this->decode($file);

        $limit = (int) config('imports.text_limit');
        $length = mb_strlen($document['text']);

        // Refused rather than truncated. A report cut off at the limit loses rows
        // from the middle without saying so, and a draft that is quietly missing
        // rows is worse than one that was never made — the person reviewing it has
        // no way to know which of their rows are gone.
        if ($length > $limit) {
            $this->fail("Dokumen terlalu panjang: {$length} karakter, batas {$limit}. Bagi menjadi beberapa berkas.");
        }

        return $document;
    }

    /**
     * @return array{text: string, grid: list<array<int, string>>|null}
     */
    private function decode(UploadedFile $file): array
    {
        $content = (string) $file->get();

        if ($content === '') {
            $this->fail('Berkas kosong.');
        }

        if (str_starts_with($content, '%PDF-')) {
            return ['text' => $this->pdf($content), 'grid' => null];
        }

        if (str_starts_with($content, self::OLE_MAGIC)) {
            $this->fail('Berkas Word/Excel format lama tidak didukung. Simpan ulang sebagai .docx atau .xlsx.');
        }

        if (str_starts_with($content, "PK\x03\x04")) {
            return $this->office($file);
        }

        return ['text' => $this->text($content), 'grid' => null];
    }

    /**
     * The bytes read as a plain text file.
     *
     * A file whose content is comma-separated is a delimited text file whatever it
     * is named, so nothing here looks at the filename: the extension is chosen by
     * whoever uploads, which makes it a hint rather than a gate. The UI names the
     * formats it wants; the bytes decide what is actually read.
     */
    private function text(string $content): string
    {
        // Excel writes a byte order mark in front of UTF-8 text. It is invisible in
        // the file and would otherwise become part of the first column's name,
        // turning a header that matches the contract into one that does not.
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        if (str_contains($content, "\0")) {
            $this->fail($this->unsupported());
        }

        if (! mb_check_encoding($content, 'UTF-8')) {
            $this->fail('Isi berkas bukan teks UTF-8 yang valid. Simpan ulang sebagai .txt UTF-8.');
        }

        return $content;
    }

    /**
     * An Office Open XML document, read from the ZIP it actually is.
     *
     * @return array{text: string, grid: list<array<int, string>>|null}
     */
    private function office(UploadedFile $file): array
    {
        $path = $file->getRealPath();

        $zip = new ZipArchive;

        if ($path === false || $zip->open($path) !== true) {
            $this->fail($this->unsupported());
        }

        try {
            if ($zip->locateName('word/document.xml') !== false) {
                return ['text' => $this->word($zip), 'grid' => null];
            }

            if ($zip->locateName('xl/workbook.xml') !== false) {
                $grid = $this->sheet($zip);

                return ['text' => $this->gridText($grid), 'grid' => $grid];
            }

            // A ZIP that is neither: a .pptx, an .odt, or something that only
            // claims to be a document. Named as unsupported rather than guessed at.
            $this->fail($this->unsupported());
        } finally {
            $zip->close();
        }
    }

    /**
     * The paragraphs of a .docx, one per line.
     */
    private function word(ZipArchive $zip): string
    {
        $document = $this->xml($zip->getFromName('word/document.xml'));

        $lines = [];

        // Every paragraph, including the empty ones: a blank line in a Word
        // document separates two blocks of text, and dropping it would run them
        // together in the text the model reads.
        foreach ($this->xpath($document)->query('//w:p') as $paragraph) {
            $lines[] = $this->textOf($paragraph, './/w:t');
        }

        return implode("\n", $lines);
    }

    /**
     * The first worksheet as rows of cells.
     *
     * @return list<array<int, string>>
     */
    private function sheet(ZipArchive $zip): array
    {
        $shared = $this->sharedStrings($zip);

        $sheet = $this->xml($zip->getFromName('xl/worksheets/sheet1.xml'));

        $rows = [];
        $width = 0;

        foreach ($this->xpath($sheet)->query('//x:sheetData/x:row') as $row) {
            $cells = [];
            $next = 0;

            foreach ($this->xpath($sheet)->query('x:c', $row) as $cell) {
                // The cell's own reference is what places it. A row that omits a
                // cell — because it is empty and the writer skipped it — would
                // otherwise shift every value after the gap one column to the
                // left, so a price would be read as a quantity.
                $reference = $cell->getAttribute('r');
                $index = $reference === '' ? null : $this->columnIndex($reference);

                $at = $index ?? $next;

                $cells[$at] = $this->cellValue($cell, $shared);
                $next = $at + 1;
            }

            if ($this->blank($cells)) {
                continue;
            }

            // Kept keyed by column, not re-indexed. A row that skips a cell holds
            // its values at their real column numbers, and collapsing them with
            // array_values would pull the price left into the quantity's place —
            // the exact shift the cell reference above exists to prevent.
            ksort($cells);

            $width = max($width, array_key_last($cells) + 1);

            $rows[] = $cells;
        }

        // Padded to the widest row so every row has the same number of cells. A
        // short row is a row with missing values, and the per-row validation says
        // which value is missing; a column-count error would say only that the row
        // is the wrong shape, which is true and not actionable.
        return array_map(function (array $cells) use ($width): array {
            $row = [];

            for ($column = 0; $column < $width; $column++) {
                $row[] = $cells[$column] ?? '';
            }

            return $row;
        }, $rows);
    }

    /**
     * The shared string table, indexed by the order the sheet refers to it.
     *
     * @return list<string>
     */
    private function sharedStrings(ZipArchive $zip): array
    {
        $contents = $zip->getFromName('xl/sharedStrings.xml');

        if ($contents === false) {
            return [];
        }

        $document = $this->xml($contents);

        $strings = [];

        // A string is one <si> and its text may be split across several runs, so
        // every <t> below it is concatenated rather than the first one taken —
        // otherwise a styled word loses everything after its first change of font.
        foreach ($this->xpath($document)->query('//x:si') as $item) {
            $strings[] = $this->textOf($item, './/x:t');
        }

        return $strings;
    }

    /**
     * The display value of one spreadsheet cell.
     *
     * @param  list<string>  $shared
     */
    private function cellValue(DOMNode $cell, array $shared): string
    {
        $type = $cell->getAttribute('t');

        if ($type === 'inlineStr') {
            return $this->textOf($cell, './/x:is//x:t');
        }

        $value = $this->textOf($cell, 'x:v');

        // "s" means the value is an index into the shared table, not the text.
        // Reading it as the text would put "3" in every cell of a sheet whose
        // strings are shared, which looks like data rather than a missing lookup.
        if ($type === 's') {
            return $shared[(int) $value] ?? '';
        }

        return $value;
    }

    /**
     * The text of a PDF, or a refusal that says why it cannot be read.
     */
    private function pdf(string $content): string
    {
        try {
            $text = (new Parser)->parseContent($content)->getText();
        } catch (Throwable $e) {
            // A malformed or encrypted PDF. Reported rather than swallowed,
            // because a file the library cannot open and a provider outage look
            // identical from the outside and only one of them is a bug.
            report($e);

            $this->fail('PDF ini tidak bisa dibaca. Pastikan berkasnya tidak rusak atau terkunci.');
        }

        // A scan is an image wrapped in a PDF: structurally valid, and with no text
        // layer at all. Pulling text out of it needs OCR, which needs a binary the
        // deploy image does not have, so the honest answer is to say so rather than
        // return an empty draft the person cannot tell from a broken one.
        if (trim($text) === '') {
            $this->fail('PDF ini tampaknya hasil scan: tidak ada teks yang bisa dibaca. Kirim PDF yang teksnya bisa dipilih, atau salin isinya ke .txt.');
        }

        return $text;
    }

    /**
     * A grid flattened to text, for the model to read if it has to.
     *
     * @param  list<array<int, string>>  $grid
     */
    private function gridText(array $grid): string
    {
        return implode("\n", array_map(
            fn (array $row): string => implode("\t", $row),
            $grid,
        ));
    }

    /**
     * The zero-based column a cell reference names: "A1" is 0, "BC12" is 54.
     */
    private function columnIndex(string $reference): ?int
    {
        if (preg_match('/^([A-Z]+)\d+$/', strtoupper($reference), $matches) !== 1) {
            return null;
        }

        $index = 0;

        foreach (str_split($matches[1]) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index - 1;
    }

    /**
     * Every cell of the node's subtree, concatenated.
     */
    private function textOf(DOMNode $node, string $expression): string
    {
        $text = '';

        foreach ($this->xpath($node->ownerDocument)->query($expression, $node) as $match) {
            $text .= $match->textContent;
        }

        return $text;
    }

    /**
     * Whether every cell in the row is empty.
     *
     * @param  array<int, string>  $cells
     */
    private function blank(array $cells): bool
    {
        return $cells === [] || implode('', $cells) === '';
    }

    /**
     * An XML part of the package, parsed with warnings turned into a refusal.
     */
    private function xml(string|false $contents): DOMDocument
    {
        $document = new DOMDocument;

        $previous = libxml_use_internal_errors(true);

        $loaded = $contents === false
            ? false
            : $document->loadXML($contents, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded === false) {
            $this->fail($this->unsupported());
        }

        return $document;
    }

    /**
     * An XPath over one part, with the two OOXML dialects registered by prefix.
     *
     * Both are registered whatever the part is. The parts are read one namespace
     * at a time and the unused prefix costs nothing, which is cheaper than
     * threading the right one through every call.
     */
    private function xpath(DOMDocument $document): DOMXPath
    {
        $xpath = new DOMXPath($document);

        $xpath->registerNamespace('x', self::SPREADSHEET_NS);
        $xpath->registerNamespace('w', self::WORD_NS);

        return $xpath;
    }

    private function unsupported(): string
    {
        return 'Berkas ini bukan dokumen teks, Word, Excel, atau PDF yang bisa dibaca. Gunakan .txt, .xlsx, .docx, atau .pdf.';
    }

    /**
     * @throws ValidationException
     */
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => [$message]]);
    }
}
