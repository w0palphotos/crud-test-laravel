<?php

namespace Tests\Feature\Web;

use App\Models\Sale;
use App\Models\SaleImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The images filed with a sale.
 *
 * Two writes: one to put an image on the sale, one to take it off. What these
 * cover is the write path — the upload endpoint's own validation already has its
 * tests, and the delete path is its own because a file is storage and a row is
 * the database, and the two do not go together automatically.
 */
class SaleImageTest extends TestCase
{
    use RefreshDatabase;

    private const DISK = 'images';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('images.disk', self::DISK);
        Storage::fake(self::DISK);
    }

    private function admin(): void
    {
        $this->actingAs(User::factory()->admin()->create());
    }

    private function uploaded(string $name): UploadedFile
    {
        return UploadedFile::fake()->create($name, null, 'image/jpeg');
    }

    public function test_an_image_is_filed_with_the_sale(): void
    {
        $this->admin();

        $sale = Sale::factory()->create();

        $this->post(
            route('sales.images.store', $sale),
            ['image' => $this->uploaded('front.jpg')]
        )->assertRedirect(route('sales.show', $sale));

        $this->assertSame(1, SaleImage::count());
        $image = SaleImage::sole();

        // Zero-based, because the sort order is the count of what is already there.
        $this->assertSame(0, $image->sort_order);
        Storage::disk(self::DISK)->assertExists($image->path);
    }

    public function test_a_second_image_takes_the_next_sort_order(): void
    {
        $this->admin();

        $sale = Sale::factory()->create();

        $this->post(route('sales.images.store', $sale), ['image' => $this->uploaded('a.jpg')]);
        $this->post(route('sales.images.store', $sale), ['image' => $this->uploaded('b.jpg')]);

        $this->assertSame(
            [0, 1],
            $sale->fresh()->images()->orderBy('sort_order')->pluck('sort_order')->all(),
        );

        $this->assertSame(
            [0, 1],
            $sale->fresh()->images()->orderBy('sort_order')->pluck('sort_order')->all(),
        );

        $paths = $sale->fresh()->images()->orderBy('sort_order')->pluck('path')->all();

        $this->assertCount(2, $paths);
        $this->assertStringStartsWith('uploads/', $paths[0]);
        $this->assertStringStartsWith('uploads/', $paths[1]);
        $this->assertNotSame($paths[0], $paths[1]);
    }

    public function test_an_image_is_taken_off_the_sale(): void
    {
        $this->admin();

        $sale = Sale::factory()->create();
        $image = $sale->images()->create(['path' => 'uploads/front.jpg', 'sort_order' => 0]);

        Storage::disk(self::DISK)->put($image->path, 'not really a jpeg');

        $this->delete(route('sales.images.destroy', [$sale, $image]))
            ->assertRedirect(route('sales.show', $sale));

        $this->assertDatabaseMissing('sale_images', ['id' => $image->id]);
        Storage::disk(self::DISK)->assertMissing($image->path);
    }

    public function test_an_image_belonging_to_another_sale_cannot_be_taken_off(): void
    {
        $this->admin();

        $mine = Sale::factory()->create();
        $theirs = Sale::factory()->create();

        $foreign = $theirs->images()->create(['path' => 'uploads/theirs.jpg', 'sort_order' => 0]);

        Storage::disk(self::DISK)->put('uploads/theirs.jpg', 'not really a jpeg');

        $this->delete(route('sales.images.destroy', [$mine, $foreign]))
            ->assertNotFound();

        $this->assertDatabaseHas('sale_images', ['id' => $foreign->id]);
        Storage::disk(self::DISK)->assertExists('uploads/theirs.jpg');
    }

    public function test_the_image_endpoints_are_closed_to_ordinary_accounts(): void
    {
        $sale = Sale::factory()->create();

        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->post(route('sales.images.store', $sale), ['image' => $this->uploaded('a.jpg')])
            ->assertForbidden();

        $foreign = Sale::factory()->create()->images()->create(['path' => 'uploads/b.jpg', 'sort_order' => 0]);

        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->delete(route('sales.images.destroy', [$sale, $foreign]))
            ->assertForbidden();

        $foreign->delete();

        $this->assertSame(0, SaleImage::count());
    }
}
