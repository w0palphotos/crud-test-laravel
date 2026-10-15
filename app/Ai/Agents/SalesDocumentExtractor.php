<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\StructuredAgentResponse;

/**
 * Reads a document that is not a table and returns the sales rows it contains.
 *
 * This is the only part of the import that guesses, and everything around it is
 * built to make that guess visible rather than authoritative. It does not write:
 * its output is a draft the person sees on screen, corrects, and confirms, and the
 * draft is then validated by ImportSales — which resolves every name against the
 * database and refuses the whole file if one of them is not there.
 *
 * The instructions spend as much space on what not to do as on what to do, because
 * every one of those refusals is a failure this application would otherwise make
 * silently. An aggregate report is the case that matters most: it has a sales
 * name, a city and a product on every line, so it can be mapped row for row into
 * something that looks like a sale, and the numbers would be an average and a total
 * filed as a unit price and a quantity. There is no column contract to catch that
 * any more — removing CSV removed the header check that used to refuse it — so the
 * refusal is stated here and the draft is the second line of defence.
 *
 * It is not conversational: a document is read once, and remembering the previous
 * one would let an earlier upload colour the next one.
 */
class SalesDocumentExtractor implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return <<<'TEXT'
        Kamu membaca satu dokumen berbahasa Indonesia yang berisi daftar transaksi
        penjualan, lalu mengeluarkan baris-barisnya.

        Setiap baris punya lima nilai: sales (nama tenaga penjual), kota, produk,
        jumlah, dan harga.

        Aturan:
        - Salin nilai apa adanya dari dokumen. Jangan mengarang nama, produk, atau
          angka, dan jangan memperbaiki ejaannya.
        - Kalau sebuah baris tidak memuat salah satu nilai, kosongkan nilai itu.
          Jangan menebaknya dan jangan mengambil nilai dari baris lain.
        - Jangan mengubah angka: jangan menambahkan pemisah ribuan dan jangan
          mengganti titik menjadi koma. Tulis persis seperti di dokumen.
        - Jangan menggabungkan dua baris dan jangan memecah satu baris menjadi dua.
        - Lewati baris judul, baris kosong, dan baris total.
        - Kalau dokumen ini bukan daftar transaksi — misalnya rekap agregat
          (rata-rata atau total per orang), ringkasan, laporan bulanan, atau daftar
          produk — kembalikan daftar kosong. Dokumen seperti itu tidak bisa menjadi
          penjualan tanpa mengarang pelanggan, tanggal, dan rincian item yang tidak
          ada di dalamnya.
        TEXT;
    }

    /**
     * Get the agent's structured output schema.
     *
     * Every value is a string, including the quantity and the price. They are
     * validated by ImportSales, which reads them exactly as a person typing them
     * into the manual form would, and a number type here would let the model
     * normalise "15.000" into 15000 before that validation ever saw it — turning a
     * value the import is supposed to refuse into one it accepts.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'rows' => $schema->array()->items($schema->object([
                'sales' => $schema->string(),
                'kota' => $schema->string(),
                'produk' => $schema->string(),
                'jumlah' => $schema->string(),
                'harga' => $schema->string(),
            ])),
        ];
    }

    /**
     * The document read into rows, shaped like ImportSales' own output.
     *
     * @return list<array{row: int, cells: array<string, string>}>
     */
    public function extract(string $text): array
    {
        $response = $this->prompt(
            $text,
            provider: Lab::Gemini,
            // Left unset so config/ai.php decides, for the reason
            // AssistantController gives: a retired model answers 404 and belongs
            // in configuration rather than in a code change.
            timeout: 30,
        );

        // A structured agent answers with the schema filled in. Anything else means
        // the provider did not honour the schema, which is a configuration or
        // outage problem rather than a document with no rows — it is reported as an
        // empty draft here, and the endpoint turns that into a message.
        $extracted = $response instanceof StructuredAgentResponse
            ? ($response->toArray()['rows'] ?? [])
            : [];

        return $this->rows($extracted);
    }

    /**
     * @return list<array{row: int, cells: array<string, string>}>
     */
    private function rows(mixed $extracted): array
    {
        if (! is_array($extracted)) {
            return [];
        }

        $rows = [];

        foreach ($extracted as $values) {
            if (! is_array($values)) {
                continue;
            }

            // Numbered here rather than taken from the model, so the row a message
            // names is the row the person counted on the review screen. A missing
            // or repeated number from the model would make the two disagree.
            $rows[] = [
                'row' => count($rows) + 1,
                'cells' => [
                    'sales' => (string) ($values['sales'] ?? ''),
                    'kota' => (string) ($values['kota'] ?? ''),
                    'produk' => (string) ($values['produk'] ?? ''),
                    'jumlah' => (string) ($values['jumlah'] ?? ''),
                    'harga' => (string) ($values['harga'] ?? ''),
                ],
            ];
        }

        return $rows;
    }
}
