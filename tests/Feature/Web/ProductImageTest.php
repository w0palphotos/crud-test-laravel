<?php

namespace Tests\Feature\Web;

use App\Actions\SyncProductImages;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The product gallery.
 *
 * The table, the relation and the ordering already existed; what these cover is the
 * write path that was missing, and the one thing it does that the sale note's inline
 * images cannot: deleting the file along with the row.
 *
 * Files are faked and then written by hand rather than uploaded through the endpoint,
 * because the endpoint has its own tests. What matters here is only that the list
 * arrives, becomes rows in order, and that a path naming a file the disk does not
 * hold is refused — the disk is the authority on whether a path is real.
 */
class ProductImageTest extends TestCase
{
    use RefreshDatabase;

    private const DISK = 'images';

    protected function setUp(): void
    {
        parent::setUp();

        // Both, because the controller reads the disk from configuration and the
        // assertion reads it from the filesystem: pointed at the same place, a test
        // cannot pass by writing to one and looking in the other.
        config()->set('images.disk', self::DISK);
        Storage::fake(self::DISK);
    }

    private function uploaded(string $name): string
    {
        $path = 'uploads/'.$name;

        Storage::disk(self::DISK)->put($path, 'not really a jpeg, never decoded');

        return $path;
    }

    private function admin(): void
    {
        $this->actingAs(User::factory()->admin()->create());
    }

    /**
     * @return array<string, mixed>
     */
    private function fields(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Helm ORIGINAL',
            'price' => '150000',
            'stock' => 4,
        ], $overrides);
    }

    public function test_a_new_product_keeps_the_gallery_it_arrives_with(): void
    {
        $this->admin();

        $this->post('/products', $this->fields([
            'images' => [
                ['path' => $this->uploaded('front.jpg')],
                ['path' => $this->uploaded('side.jpg')],
            ],
        ]))->assertRedirect(route('products.index'));

        $product = Product::sole();

        $this->assertSame(2, $product->images()->count());

        // Zero-based and gapless, because the order is the list's order: the page
        // arranged them and the catalogue has to show the same arrangement.
        $this->assertSame(
            ['uploads/front.jpg', 'uploads/side.jpg'],
            $product->images()->orderBy('sort_order')->pluck('path')->all(),
        );
    }

    public function test_a_new_product_can_be_created_without_images(): void
    {
        $this->admin();

        $this->post('/products', $this->fields([
            'images' => [],
        ]))->assertRedirect(route('products.index'));

        $product = Product::sole();
        $this->assertSame(0, $product->images()->count());
    }

    public function test_reordering_the_list_reorders_the_rows(): void
    {
        $this->admin();

        $product = Product::factory()->create();
        $first = $product->images()->create(['path' => $this->uploaded('a.jpg'), 'sort_order' => 0]);
        $second = $product->images()->create(['path' => $this->uploaded('b.jpg'), 'sort_order' => 1]);

        $this->patch("/products/{$product->id}", $this->fields([
            'images' => [
                ['id' => $second->id],
                ['id' => $first->id],
            ],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(0, $second->fresh()->sort_order);
        $this->assertSame(1, $first->fresh()->sort_order);

        // And the relation, which is what the page reads, agrees.
        $this->assertSame(
            [$second->id, $first->id],
            $product->fresh()->images->pluck('id')->all(),
        );
    }

    public function test_a_caption_is_saved_and_a_blank_one_is_stored_as_nothing(): void
    {
        $this->admin();

        $product = Product::factory()->create();

        $this->patch("/products/{$product->id}", $this->fields([
            'images' => [
                ['path' => $this->uploaded('a.jpg'), 'alt_text' => '  Tampak depan  '],
                ['path' => $this->uploaded('b.jpg'), 'alt_text' => '   '],
            ],
        ]))->assertSessionHasNoErrors();

        $rows = $product->images()->orderBy('sort_order')->get();

        // Trimmed, because the column is the model's own text and the whitespace came
        // from an input box, not from anyone's intent.
        $this->assertSame('Tampak depan', $rows[0]->alt_text);

        // Blank and absent are the same answer to "is there a caption", and storing
        // both spellings of no would make every later read ask twice.
        $this->assertNull($rows[1]->alt_text);
    }

    public function test_removing_an_image_deletes_its_row_and_its_file(): void
    {
        $this->admin();

        $product = Product::factory()->create();

        $keptPath = $this->uploaded('kept.jpg');
        $droppedPath = $this->uploaded('dropped.jpg');

        $kept = $product->images()->create(['path' => $keptPath, 'sort_order' => 0]);
        $dropped = $product->images()->create(['path' => $droppedPath, 'sort_order' => 1]);

        $this->patch("/products/{$product->id}", $this->fields([
            'images' => [['id' => $kept->id]],
        ]))->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('product_images', ['id' => $dropped->id]);
        Storage::disk(self::DISK)->assertMissing($droppedPath);

        // The one that stayed keeps its file: the delete follows the row, not the
        // directory.
        Storage::disk(self::DISK)->assertExists($keptPath);
    }

    /**
     * A product with no picture is a hole in the catalogue, so the request refuses
     * the empty list rather than acting on it.
     *
     * The action below still does the emptying — it is a capability, and what a
     * caller may not request is not the same thing as what it cannot do.
     */
    public function test_an_empty_gallery_empties_the_gallery(): void
    {
        $this->admin();

        $product = Product::factory()->create();

        $path = $this->uploaded('only.jpg');
        $product->images()->create(['path' => $path, 'sort_order' => 0]);

        // An empty list is now a legal request on edit: it empties the gallery
        // rather than being refused. Absence, not emptiness, is what leaves it
        // alone.
        $this->patch("/products/{$product->id}", $this->fields([
            'images' => [],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(0, ProductImage::count());
        Storage::disk(self::DISK)->assertMissing($path);
    }

    public function test_the_action_still_empties_a_gallery_when_asked_to(): void
    {
        $product = Product::factory()->create();

        $path = $this->uploaded('only.jpg');
        $product->images()->create(['path' => $path, 'sort_order' => 0]);

        $this->assertSame(1, ProductImage::count());

        app(SyncProductImages::class)->handle($product, []);

        $this->assertSame(0, ProductImage::count());
        Storage::disk(self::DISK)->assertMissing($path);
    }

    public function test_a_gallery_that_is_not_submitted_is_left_alone(): void
    {
        $this->admin();

        $product = Product::factory()->create();

        $path = $this->uploaded('a.jpg');
        $image = $product->images()->create(['path' => $path, 'alt_text' => 'Depan', 'sort_order' => 0]);

        // No `images` key at all: a partial update, the same rule every other field
        // in this request follows. It is also now the only way to say "leave the
        // gallery as it is" — an empty list is refused, so absence has to carry
        // that meaning on its own.
        $this->patch("/products/{$product->id}", [
            'name' => 'Renamed',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('product_images', ['id' => $image->id, 'alt_text' => 'Depan']);
        Storage::disk(self::DISK)->assertExists($path);
    }

    public function test_a_path_outside_the_upload_directory_is_refused(): void
    {
        $this->admin();

        $this->post('/products', $this->fields([
            'images' => [['path' => 'products/elsewhere.jpg']],
        ]))->assertSessionHasErrors('images.0.path');

        $this->assertSame(0, ProductImage::count());
    }

    public function test_a_path_the_disk_does_not_hold_is_refused(): void
    {
        $this->admin();

        // A path is a string a client can type. A row pointing at a file nobody
        // uploaded is a gallery entry no one can ever be shown.
        $this->post('/products', $this->fields([
            'images' => [['path' => 'uploads/never-uploaded.jpg']],
        ]))->assertSessionHasErrors('images.0.path');

        $this->assertSame(0, ProductImage::count());
    }

    public function test_an_image_belonging_to_another_product_is_refused(): void
    {
        $this->admin();

        $mine = Product::factory()->create();
        $theirs = Product::factory()->create();

        $foreign = $theirs->images()->create(['path' => $this->uploaded('theirs.jpg'), 'sort_order' => 0]);

        // The id is real, but it is not this product's, so the rule that searches by
        // id alone would let it through and the write would silently move someone
        // else's image.
        $this->patch("/products/{$mine->id}", $this->fields([
            'images' => [['id' => $foreign->id]],
        ]))->assertSessionHasErrors('images.0.id');

        $this->assertSame($theirs->id, $foreign->fresh()->product_id);
    }

    public function test_the_edit_page_lists_the_gallery_in_order(): void
    {
        $this->admin();

        $product = Product::factory()->create();

        $second = $product->images()->create(['path' => $this->uploaded('second.jpg'), 'sort_order' => 1]);
        $first = $product->images()->create(['path' => $this->uploaded('first.jpg'), 'sort_order' => 0]);

        $this->get("/products/{$product->id}/edit")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Products/Edit')
                ->has('images', 2)
                ->where('images.0.id', $first->id)
                ->where('images.0.path', 'uploads/first.jpg')
                ->where('images.1.id', $second->id)
                ->whereNot('images.0.url', '')
            );
    }

    public function test_the_create_page_arrives_with_an_empty_gallery(): void
    {
        $this->admin();

        // Present rather than absent, so the component that renders it finds the same
        // shape on both pages.
        $this->get('/products/create')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Products/Create')
                ->where('images', [])
            );
    }

    public function test_the_gallery_is_open_to_ordinary_accounts(): void
    {
        $product = Product::factory()->create();

        // Sales (non-admin) may update products — the gate opens instead of a 403.
        // The image from the uploaded file path gets synced, so the gallery count rises.
        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->patch("/products/{$product->id}", $this->fields([
                'images' => [['path' => $this->uploaded('a.jpg')]],
            ]))
            ->assertStatus(302);

        $this->assertSame(1, ProductImage::count());
    }

    public function test_deleting_a_product_takes_its_gallery_files_with_it(): void
    {
        $this->admin();

        $product = Product::factory()->create();

        $kept = $this->uploaded('front.jpg');
        $gone = $this->uploaded('side.jpg');

        $product->images()->create(['path' => $kept, 'sort_order' => 0]);
        $product->images()->create(['path' => $gone, 'sort_order' => 1]);

        $this->delete("/products/{$product->id}")
            ->assertRedirect(route('products.index'));

        // The rows went on their own — product_images cascades — but nothing in a
        // schema can delete a file, so without this the bucket would keep a copy of
        // every deleted product's pictures that nothing points at any more.
        Storage::disk(self::DISK)->assertMissing($gone);
        $this->assertSame(0, ProductImage::count());
    }

    public function test_deleting_a_product_leaves_another_products_files_alone(): void
    {
        $this->admin();

        $doomed = Product::factory()->create();
        $other = Product::factory()->create();

        $survivor = $this->uploaded('other.jpg');

        $doomed->images()->create(['path' => $this->uploaded('doomed.jpg'), 'sort_order' => 0]);
        $other->images()->create(['path' => $survivor, 'sort_order' => 0]);

        $this->delete("/products/{$doomed->id}");

        // One row owns one file, so the delete follows the gallery it was given and
        // stops there.
        Storage::disk(self::DISK)->assertExists($survivor);
    }

    public function test_the_product_table_carries_a_thumbnail_and_null_when_there_is_no_gallery(): void
    {
        $this->admin();

        $illustrated = Product::factory()->create(['name' => 'Ada Gambar']);
        $illustrated->images()->create([
            'path' => $this->uploaded('front.jpg'),
            'alt_text' => 'Depan',
            'sort_order' => 0,
        ]);

        Product::factory()->create(['name' => 'Tanpa Gambar']);

        $this->get('/products')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Products/Index')
                ->where('products.data.0.thumbnail_url', null)
                // Newest first, so the illustrated one is second.
                ->where('products.data.1.thumbnail_url', Storage::disk(self::DISK)->url('uploads/front.jpg'))
            );
    }
}
