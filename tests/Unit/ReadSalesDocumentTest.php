<?php

namespace Tests\Unit;

use App\Actions\ReadSalesDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use ZipArchive;

/**
 * Reading a document, without HTTP in the way.
 *
 * The parsing is the part of this feature that is easiest to get wrong and
 * hardest to see: a spreadsheet whose shared strings were not resolved, or whose
 * skipped cells shifted every value one column left, produces a draft that looks
 * entirely plausible. So the fixtures here are real ZIP packages and a real PDF,
 * built by hand rather than faked, and the assertions are on the exact cells.
 */
class ReadSalesDocumentTest extends TestCase
{
    private const SPREADSHEET_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const WORD_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /**
     * @return array{text: string, grid: list<array<int, string>>|null}
     */
    private function read(UploadedFile $file): array
    {
        return (new ReadSalesDocument)->read($file);
    }

    /**
     * The refusal message for a file that must not be read.
     */
    private function refusal(UploadedFile $file): string
    {
        try {
            $this->read($file);
        } catch (ValidationException $exception) {
            return $exception->errors()['file'][0];
        }

        $this->fail('Expected the document to be refused, but it was read.');
    }

    private function file(string $name, string $contents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'read-document-test');
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, null, null, true);
    }

    /**
     * @param  array<string, string>  $entries
     */
    private function archive(string $name, array $entries): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'read-document-test');

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);

        foreach ($entries as $entry => $contents) {
            $zip->addFromString($entry, $contents);
        }

        $zip->close();

        return new UploadedFile($path, $name, null, null, true);
    }

    private function docx(string $body): UploadedFile
    {
        $namespace = self::WORD_NS;

        return $this->archive('laporan.docx', [
            'word/document.xml' => '<?xml version="1.0" encoding="UTF-8"?>'
                ."<w:document xmlns:w=\"{$namespace}\"><w:body>{$body}</w:body></w:document>",
        ]);
    }

    /**
     * @param  list<string>  $shared
     */
    private function xlsx(string $rows, array $shared = []): UploadedFile
    {
        $namespace = self::SPREADSHEET_NS;

        $entries = [
            'xl/workbook.xml' => "<?xml version=\"1.0\"?><workbook xmlns=\"{$namespace}\"/>",
            'xl/worksheets/sheet1.xml' => '<?xml version="1.0" encoding="UTF-8"?>'
                ."<worksheet xmlns=\"{$namespace}\"><sheetData>{$rows}</sheetData></worksheet>",
        ];

        if ($shared !== []) {
            $items = implode('', array_map(fn (string $value): string => "<si><t>{$value}</t></si>", $shared));

            $entries['xl/sharedStrings.xml'] = '<?xml version="1.0" encoding="UTF-8"?>'
                ."<sst xmlns=\"{$namespace}\">{$items}</sst>";
        }

        return $this->archive('laporan.xlsx', $entries);
    }

    public function test_a_plain_text_file_comes_back_as_text_with_no_grid(): void
    {
        $document = $this->read($this->file('laporan.txt', "sales,kota\nAndi,Jakarta"));

        $this->assertSame("sales,kota\nAndi,Jakarta", $document['text']);
        $this->assertNull($document['grid']);
    }

    public function test_a_byte_order_mark_is_removed(): void
    {
        $document = $this->read($this->file('laporan.txt', "\xEF\xBB\xBF".'sales,kota'));

        // Left on, the mark becomes part of the first column's name and the header
        // stops matching the contract, so this is the difference between reading
        // an Excel-saved file and refusing it.
        $this->assertSame('sales,kota', $document['text']);
    }

    public function test_a_docx_yields_its_paragraphs_one_per_line(): void
    {
        $document = $this->read($this->docx(
            '<w:p><w:r><w:t>Laporan Penjualan</w:t></w:r></w:p>'
            .'<w:p><w:r><w:t>Andi</w:t></w:r><w:r><w:t> Jakarta</w:t></w:r></w:p>'
        ));

        // Two runs in one paragraph are one line, not two: a run boundary is where
        // formatting changed, not where the text did.
        $this->assertSame("Laporan Penjualan\nAndi Jakarta", $document['text']);
        $this->assertNull($document['grid']);
    }

    public function test_an_xlsx_yields_a_grid_with_shared_strings_resolved(): void
    {
        $document = $this->read($this->xlsx(
            '<row r="1">'
            .'<c r="A1" t="s"><v>0</v></c>'
            .'<c r="B1" t="s"><v>1</v></c>'
            .'<c r="C1" t="s"><v>2</v></c>'
            .'</row>'
            .'<row r="2">'
            .'<c r="A2" t="s"><v>3</v></c>'
            .'<c r="B2" t="s"><v>4</v></c>'
            .'<c r="C2"><v>15000</v></c>'
            .'</row>',
            ['sales', 'kota', 'produk', 'Andi', 'Jakarta'],
        ));

        $this->assertSame([
            ['sales', 'kota', 'produk'],
            ['Andi', 'Jakarta', '15000'],
        ], $document['grid']);
    }

    public function test_a_cell_the_writer_skipped_does_not_shift_the_cells_after_it(): void
    {
        $document = $this->read($this->xlsx(
            '<row r="1">'
            .'<c r="A1" t="s"><v>0</v></c>'
            .'<c r="B1" t="s"><v>1</v></c>'
            .'<c r="C1" t="s"><v>2</v></c>'
            .'<c r="D1" t="s"><v>3</v></c>'
            .'</row>'
            // B2 is absent: row 2 is Andi, an empty city, 2 and 15000.
            .'<row r="2">'
            .'<c r="A2" t="s"><v>4</v></c>'
            .'<c r="D2"><v>2</v></c>'
            .'<c r="E2"><v>15000</v></c>'
            .'</row>',
            ['sales', 'kota', 'produk', 'jumlah', 'Andi'],
        ));

        // The gap stays a gap. Without the cell reference the price would land in
        // the city's column and the draft would be wrong in a way that reads fine.
        $this->assertSame(['Andi', '', '', '2', '15000'], $document['grid'][1]);
    }

    public function test_a_pdf_yields_its_text(): void
    {
        $document = $this->read($this->file('laporan.pdf', $this->pdf('Andi Jakarta Helm 2 15000')));

        $this->assertStringContainsString('Andi Jakarta Helm 2 15000', $document['text']);
        $this->assertNull($document['grid']);
    }

    public function test_a_pdf_without_a_text_layer_is_refused_as_a_scan(): void
    {
        $message = $this->refusal($this->file('scan.pdf', $this->pdf('')));

        $this->assertStringContainsString('hasil scan', $message);
    }

    public function test_a_legacy_office_file_is_refused_by_name(): void
    {
        $message = $this->refusal($this->file('laporan.doc', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".'rest of a compound file'));

        $this->assertStringContainsString('.docx', $message);
        $this->assertStringContainsString('.xlsx', $message);
    }

    public function test_a_zip_that_is_not_a_word_or_excel_document_is_refused(): void
    {
        $message = $this->refusal($this->archive('slides.pptx', [
            'ppt/presentation.xml' => '<?xml version="1.0"?><p:presentation/>',
        ]));

        $this->assertStringContainsString('bukan dokumen teks, Word, Excel, atau PDF', $message);
    }

    public function test_binary_bytes_are_refused(): void
    {
        $message = $this->refusal($this->file('notes.bin', "some text\0and a NUL byte"));

        $this->assertStringContainsString('bukan dokumen teks, Word, Excel, atau PDF', $message);
    }

    public function test_text_that_is_not_utf8_is_refused(): void
    {
        $latin1 = mb_convert_encoding('André,Jakarta', 'ISO-8859-1', 'UTF-8');

        $message = $this->refusal($this->file('laporan.txt', $latin1));

        $this->assertStringContainsString('UTF-8', $message);
    }

    public function test_an_empty_file_is_refused(): void
    {
        $this->assertStringContainsString('kosong', $this->refusal($this->file('laporan.txt', '')));
    }

    public function test_a_document_over_the_text_limit_is_refused_rather_than_truncated(): void
    {
        config()->set('imports.text_limit', 10);

        $message = $this->refusal($this->file('laporan.txt', str_repeat('a', 11)));

        // Refused, and the message says the length, because the alternative a
        // person would otherwise attempt is uploading the same file again.
        $this->assertStringContainsString('terlalu panjang', $message);
        $this->assertStringContainsString('11', $message);
        $this->assertStringContainsString('10', $message);
    }

    /**
     * A minimal, single-page, uncompressed PDF carrying one line of text.
     *
     * Written with a real cross-reference table because the parser walks it: a
     * PDF with the offsets wrong is a file the library cannot open, and this test
     * would then be asserting the refusal path while claiming to test the reading
     * path.
     */
    private function pdf(string $text): string
    {
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 200] /Contents 4 0 R '
                .'/Resources << /Font << /F1 5 0 R >> >> >>',
            '',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];

        $stream = 'BT /F1 12 Tf 20 150 Td ('.$text.') Tj ET';

        $objects[3] = '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream";

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $index => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n{$body}\nendobj\n";
        }

        $start = strlen($pdf);
        $size = count($objects) + 1;

        $pdf .= "xref\n0 {$size}\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<< /Size {$size} /Root 1 0 R >>\nstartxref\n{$start}\n%%EOF\n";
    }
}
