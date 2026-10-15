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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The sample files in docs/samples are importable.
 *
 * A sample that no longer imports is worse than no sample at all: it is a file
 * someone copies, and the first thing it teaches them is that this application
 * rejects files it documents as valid. The catalogue the samples name is created
 * here rather than seeded, because the point is the file against a known
 * directory — not whatever happens to be in the development database today.
 *
 * The refused samples split by which step refuses them, and that split is the
 * interesting part: a table with one bad name is read fine and refused when the
 * draft is confirmed, while an aggregate report is not a table at all and is
 * refused while it is being read.
 */
class SaleImportSampleFilesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The sales, cities and products the sample rows name.
     *
     * @var array<string, int>
     */
    private const CATALOGUE = [
        'Honda Vario 160' => 27000000,
        'Yamaha Nmax 155' => 33000000,
        'Yamaha Mio Sporty' => 21000000,
        'Suzuki Shogun 125' => 23000000,
        'Honda Kampas Rem Depan' => 1250000,
        'Suzuki Oli Mesin 10W-40' => 175000,
        'Honda Sarung Tangan' => 150000,
        'Yamaha Baterai 12V' => 900000,
    ];

    private ?Customer $customer = null;

    /**
     * @return array<string, array{string, int, float}>
     */
    public static function importableSamples(): array
    {
        return [
            'comma delimited' => ['contoh-tabel.txt', 4, 159600000.0],
            'semicolon delimited with a decimal comma' => ['contoh-tabel-titik-koma.txt', 3, 5500006.0],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function refusedAtConfirm(): array
    {
        return [
            'a name no catalogue row carries' => [
                'contoh-ditolak-nama-tidak-dikenal.txt',
                '"Helm Sapi" tidak ada di daftar',
            ],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function refusedAtExtract(): array
    {
        return [
            'an aggregate report rather than an order list' => [
                'contoh-ditolak-laporan-agregat.txt',
                'Tidak ada baris penjualan yang dikenali',
            ],
        ];
    }

    private function catalogue(): Customer
    {
        if ($this->customer !== null) {
            return $this->customer;
        }

        foreach (['Andi', 'Budi', 'Citra'] as $person) {
            Salesperson::factory()->create(['name' => $person]);
        }

        foreach (['Jakarta', 'Bandung', 'Surabaya', 'Medan'] as $city) {
            City::factory()->create(['name' => $city]);
        }

        foreach (self::CATALOGUE as $product => $price) {
            Product::factory()->create(['name' => $product, 'price' => $price]);
        }

        return $this->customer = Customer::factory()->create();
    }

    /**
     * The sample uploaded, read into a draft.
     */
    private function extract(string $sample): TestResponse
    {
        $path = base_path("docs/samples/{$sample}");

        $this->assertFileExists($path);

        return $this->post('/sales/import/extract', [
            'file' => new UploadedFile($path, $sample, null, null, true),
            'customer_id' => $this->catalogue()->id,
            'sold_at' => '2026-03-01',
        ]);
    }

    /**
     * The sample read and then confirmed, as a person would.
     */
    private function import(string $sample): TestResponse
    {
        $draft = $this->extract($sample)->assertInertia(
            fn ($page) => $page->component('Sales/ImportReview')
        );

        return $this->post('/sales/import', [
            'origin' => 'table',
            'customer_id' => $this->catalogue()->id,
            'sold_at' => '2026-03-01',
            'rows' => array_map(
                fn (array $row): array => ['cells' => $row['cells']],
                $draft->inertiaProps()['draft'],
            ),
        ]);
    }

    #[DataProvider('importableSamples')]
    public function test_the_sample_imports_exactly_what_it_says(
        string $sample,
        int $expectedSales,
        float $expectedTotal,
    ): void {
        $this->actingAs(User::factory()->admin()->create());

        $this->import($sample)->assertSessionHasNoErrors();

        $this->assertSame($expectedSales, Sale::count());

        // The totals are the check that the numbers were read as written:
        // 159.6 million only adds up if 27.000.000 stayed 27 million, and
        // 5.500.006 only arrives if the decimal comma was understood.
        $this->assertSame($expectedTotal, (float) Sale::sum('grand_total'));
    }

    #[DataProvider('refusedAtConfirm')]
    public function test_the_sample_is_refused_when_the_draft_is_confirmed(
        string $sample,
        string $expectedReason,
    ): void {
        $this->actingAs(User::factory()->admin()->create());

        $this->import($sample)->assertSessionHasErrors('file');

        $this->assertStringContainsString($expectedReason, session('errors')->first('file'));

        // These samples exist to show that a draft is all-or-nothing, so "no sale
        // was written" is the assertion that makes the point.
        $this->assertSame(0, Sale::count());
    }

    #[DataProvider('refusedAtExtract')]
    public function test_the_sample_is_refused_before_a_draft_exists(
        string $sample,
        string $expectedReason,
    ): void {
        $this->actingAs(User::factory()->admin()->create());

        // The aggregate report is not a table, so no header contract refuses it any
        // more — it reaches the model, and what the model answers for a summary is
        // nothing. Faked here to be exactly that answer.
        Ai::fakeAgent(SalesDocumentExtractor::class, fn (): array => ['rows' => []]);

        $this->extract($sample)->assertSessionHasErrors('file');

        $this->assertStringContainsString($expectedReason, session('errors')->first('file'));
        $this->assertSame(0, Sale::count());
    }

    public function test_every_file_in_the_samples_folder_is_covered_by_this_test(): void
    {
        $covered = array_merge(
            array_column(self::importableSamples(), 0),
            array_column(self::refusedAtConfirm(), 0),
            array_column(self::refusedAtExtract(), 0),
        );

        $onDisk = array_map('basename', glob(base_path('docs/samples/*.txt')) ?: []);

        sort($covered);
        sort($onDisk);

        // A new sample added without a test here would pass CI while being broken,
        // and the next person to copy it would be the one who finds out.
        $this->assertSame($onDisk, $covered);
    }
}
