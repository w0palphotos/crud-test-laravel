<?php

namespace Tests\Feature\Web;

use App\Models\City;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Salesperson;
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

        // The local disk, and a faked one at that. Nothing in this file reaches
        // a real storage backend: the point of a test is to prove the flow, not
        // to spend someone's bucket on it.
        config()->set('documents.disk', self::DISK);
        config()->set('documents.directory', 'sale-documents');
        config()->set('filesystems.disks.'.self::DISK, [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => '/storage',
        ]);

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

    /**
     * A sale, with the fields its form sends.
     *
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => Customer::factory()->create()->id,
            'salesperson_id' => Salesperson::factory()->create()->id,
            'city_id' => City::factory()->create()->id,
            'items' => [[
                'product_id' => Product::factory()->create()->id,
                'quantity' => 1,
                'unit_price' => 10000,
            ]],
        ], $overrides);
    }

    public function test_a_document_is_filed_with_the_sale(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $sale = Sale::factory()->create();

        $response = $this->post("/sales/{$sale->id}/documents", ['file' => $this->pdf()]);

        // A redirect and not a body of json.
        //
        // The form posts through Inertia, and Inertia has one shape of answer it
        // will take. A plain json reply is refused outright, with a complaint
        // about the shape of the reply rather than about the document — and the
        // document is already filed by then, so the page would carry a failure for
        // something that worked.
        $response->assertRedirect(route('sales.show', $sale));
        $response->assertSessionHas('status');

        $document = $sale->documents()->sole();

        $this->assertSame('surat-jalan-042.pdf', $document->original_name);
        $this->assertSame('application/pdf', $document->mime_type);
        $this->assertGreaterThan(0, $document->size);

        // The file is on the disk, under a generated name rather than the name the
        // person gave it.
        $this->assertStringStartsWith('sale-documents/', $document->path);
        $this->assertStringNotContainsString('suratjalan-042', $document->path);

        Storage::disk(self::DISK)->assertExists($document->path);
    }

    /**
     * The reply is never a body of json.
     *
     * The complaint Inertia makes when it is given one names the shape of the
     * reply and says nothing about the document, which is a poor way to be told
     * that a file was uploaded and then refused by the wrong sort of answer.
     */
    public function test_the_reply_is_never_a_body_of_json(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $sale = Sale::factory()->create();

        $response = $this->post("/sales/{$sale->id}/documents", ['file' => $this->pdf()]);

        $this->assertStringNotContainsString('application/json', (string) $response->headers->get('Content-Type'));

        // Following the redirect the way Inertia does brings back the sale page
        // with the document on it. This is the round trip the browser makes, and
        // the one that used to end in a complaint.
        $this->followingRedirects()
            ->post("/sales/{$sale->id}/documents", ['file' => $this->pdf()])
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Sales/Show')
                ->where('documents.0.name', 'surat-jalan-042.pdf')
            );
    }

    /**
     * A failure is answered in the shape the form can read.
     *
     * The reason arrives as an error on the field, where the form renders it. A
     * json body would be refused by the very client that made the request, and the
     * person uploading would be told the reply was malformed rather than what was
     * wrong with their storage.
     */
    public function test_a_failure_is_answered_as_an_error_on_the_field(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $sale = Sale::factory()->create();

        config()->set('filesystems.disks.unnamed', [
            'driver' => 's3',
            'key' => 'a-key',
            'secret' => 'a-secret',
            'region' => 'ap-southeast-1',
            'bucket' => null,
            'endpoint' => 'https://example.supabase.co/storage/v1/s3',
        ]);
        config()->set('documents.disk', 'unnamed');

        $response = $this->post("/sales/{$sale->id}/documents", ['file' => $this->pdf()]);

        $response->assertRedirect(route('sales.show', $sale));
        $response->assertSessionHasErrors('file');

        $this->assertStringContainsString(
            'AWS_BUCKET_DOCUMENTS',
            (string) session('errors')->first('file')
        );
    }

    public function test_the_document_records_who_filed_it(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin);

        $sale = Sale::factory()->create();

        $this->post("/sales/{$sale->id}/documents", ['file' => $this->pdf()])
            ->assertRedirect(route('sales.show', $sale));

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

    /**
     * A document is written before the sale exists, and the sale is made with it.
     *
     * The form uploads a file the moment it is chosen, because the sale it belongs
     * to does not exist yet. What the form carries is the description of the file,
     * and the sale is created with those descriptions in the same request.
     */
    public function test_a_sale_is_created_with_the_documents_chosen_for_it(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $upload = $this->post('/admin/documents', ['file' => $this->pdf()]);

        $upload->assertCreated();

        $response = $this->post('/sales', $this->payload([
            'documents' => [$upload->json()],
        ]));

        $response->assertRedirect();

        $sale = Sale::sole();

        $this->assertCount(1, $sale->documents);
        $this->assertSame('surat-jalan-042.pdf', $sale->documents->first()->original_name);
    }

    /**
     * A document is written without a sale attached to it.
     */
    public function test_a_document_can_be_written_before_there_is_a_sale(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $response = $this->post('/admin/documents', ['file' => $this->pdf()]);

        $response->assertCreated();

        $description = $response->json();

        $this->assertArrayHasKey('path', $description);
        $this->assertSame('surat-jalan-042.pdf', $description['original_name']);
        $this->assertSame('application/pdf', $description['mime_type']);
        $this->assertGreaterThan(0, $description['size']);

        // And the file is really on the disk, under a generated name.
        Storage::disk(self::DISK)->assertExists($description['path']);
        $this->assertStringNotContainsString('surat-jalan-042', $description['path']);
    }

    /**
     * A document that points at nothing is refused.
     *
     * The path is a string a client can type, and a document pointing at a file
     * nobody uploaded is a document no one can open.
     */
    public function test_a_document_pointing_at_nothing_is_refused(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->post('/sales', $this->payload([
            'documents' => [[
                'path' => 'sale-documents/tidak-ada.pdf',
                'original_name' => 'hantu.pdf',
                'mime_type' => 'application/pdf',
                'size' => 10,
            ]],
        ]))->assertSessionHasErrors('documents.0.path');

        $this->assertSame(0, Sale::count());
    }

    /**
     * A bucket that was never named is a configuration mistake, and the storage
     * client fails on it with a type error from inside its own constructor — which
     * names a parameter of a library and says nothing about what to fix.
     */
    public function test_a_bucket_that_was_never_named_says_so(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        config()->set('filesystems.disks.unnamed', [
            'driver' => 's3',
            'key' => 'a-key',
            'secret' => 'a-secret',
            'region' => 'ap-southeast-1',
            'bucket' => null,
            'endpoint' => 'https://example.supabase.co/storage/v1/s3',
        ]);
        config()->set('documents.disk', 'unnamed');

        $response = $this->post('/admin/documents', ['file' => $this->pdf()]);

        $response->assertStatus(500);

        $this->assertStringContainsString('AWS_BUCKET_DOCUMENTS', $response->json('message') ?? '');
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
