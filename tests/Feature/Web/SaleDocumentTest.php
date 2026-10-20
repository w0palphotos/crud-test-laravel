<?php

namespace Tests\Feature\Web;

use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The documents filed with a sale.
 *
 * A note is text the page shows; a document is a file the page offers. These
 * are about the second kind: a name the person recognises, a link that works,
 * and a file that is gone when the document is.
 */
class SaleDocumentTest extends TestCase
{
    use RefreshDatabase;

    private const DISK = 'documents';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('images.disk', self::DISK);
        config()->set('documents.disk', self::DISK);
        config()->set('documents.directory', 'sale-documents');

        Storage::fake(self::DISK);
    }

    private function pdf(): UploadedFile
    {
        // A real PDF, as bytes. The type is sniffed from the content and not
        // taken from the name, so a file whose bytes are a PNG is an image
        // however it is called — which is the point of the rule, and the reason
        // this cannot be UploadedFile::fake().
        $path = tempnam(sys_get_temp_dir(), 'sale-document-test');

        file_put_contents($path, "%PDF-1.4\n"
            ."1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
            ."2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
            ."3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\n"
            ."trailer<</Root 1 0 R>>\n%%EOF\n");

        return new UploadedFile($path, 'surat-jalan-042.pdf', 'application/pdf', null, true);
    }

    public function test_a_document_is_filed_with_the_sale(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $sale = Sale::factory()->create();

        $response = $this->post("/sales/{$sale->id}/documents", ['file' => $this->pdf()]);

        $response->assertCreated();

        $document = $sale->documents()->sole();

        $this->assertSame('surat-jalan-042.pdf', $document->original_name);
        $this->assertSame('application/pdf', $document->mime_type);
        $this->assertGreaterThan(0, $document->size);

        // The file is on the disk, under a generated name rather than the name the
        // person gave it.
        $this->assertStringStartsWith('sale-documents/', $document->path);
        $this->assertStringNotContainsString('surat-jalan-042', $document->path);

        Storage::disk(self::DISK)->assertExists($document->path);
    }

    public function test_the_document_records_who_filed_it(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin);

        $sale = Sale::factory()->create();

        $this->post("/sales/{$sale->id}/documents", ['file' => $this->pdf()])->assertCreated();

        $this->assertSame($admin->id, $sale->documents()->sole()->uploaded_by);
    }

    /**
     * The type is checked against the bytes, not the name.
     */
    public function test_a_type_that_is_not_a_document_is_refused(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $sale = Sale::factory()->create();

        $response = $this->post("/sales/{$sale->id}/documents", [
            'file' => UploadedFile::fake()->create('gambar.png', 4, 'image/png'),
        ]);

        $response->assertSessionHasErrors('file');

        $this->assertSame(0, $sale->documents()->count());
    }

    public function test_a_file_is_required(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $sale = Sale::factory()->create();

        $this->post("/sales/{$sale->id}/documents")
            ->assertSessionHasErrors('file');
    }

    public function test_the_sale_page_lists_the_documents(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $sale = Sale::factory()->create();

        $document = $sale->documents()->create([
            'path' => 'sale-documents/report.pdf',
            'original_name' => 'surat-jalan-042.pdf',
            'mime_type' => 'application/pdf',
            'size' => 204_800,
        ]);

        Storage::disk(self::DISK)->put($document->path, 'a document');

        $this->get("/sales/{$sale->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Sales/Show')
                ->has('documents', 1)
                ->where('documents.0.name', 'surat-jalan-042.pdf')
                ->where('documents.0.readableSize', '200 KB')
                ->where('documents.0.destroyUrl', route('sales.documents.destroy', [$sale, $document]))
            );
    }

    public function test_a_document_is_removed_with_its_file(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $sale = Sale::factory()->create();

        $document = $sale->documents()->create([
            'path' => 'sale-documents/report.pdf',
            'original_name' => 'surat-jalan-042.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
        ]);

        Storage::disk(self::DISK)->put($document->path, 'a document');

        $this->delete("/sales/{$sale->id}/documents/{$document->id}")
            ->assertRedirect(route('sales.show', $sale));

        $this->assertSame(0, $sale->documents()->count());

        // The file goes with it. A file with no row is a file nobody can find.
        Storage::disk(self::DISK)->assertMissing($document->path);
    }

    /**
     * A document is looked up through the sale, so a request naming this sale
     * and somebody else's document cannot reach it.
     */
    public function test_a_document_cannot_be_removed_through_another_sale(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $sale = Sale::factory()->create();
        $other = Sale::factory()->create();

        $document = $other->documents()->create([
            'path' => 'sale-documents/report.pdf',
            'original_name' => 'surat-jalan-042.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
        ]);

        $this->delete("/sales/{$sale->id}/documents/{$document->id}")->assertNotFound();

        $this->assertSame(1, $other->documents()->count());
    }

    public function test_an_ordinary_account_cannot_file_a_document(): void
    {
        $this->actingAs(User::factory()->create());

        $sale = Sale::factory()->create();

        $this->post("/sales/{$sale->id}/documents", ['file' => $this->pdf()])
            ->assertForbidden();
    }

    public function test_a_guest_cannot_file_a_document(): void
    {
        $sale = Sale::factory()->create();

        $this->post("/sales/{$sale->id}/documents", ['file' => $this->pdf()])
            ->assertRedirect(route('login'));
    }
}
