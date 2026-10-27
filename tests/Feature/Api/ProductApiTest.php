<?php

namespace Tests\Feature\Api;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductApiTest extends TestCase
{
    use RefreshDatabase;

    private const DISK = 'images';

    protected function setUp(): void
    {
        parent::setUp();

        // The gallery's rules ask the disk whether a path names a real file, so a
        // test that posts `images` needs one that is there. Faked rather than
        // uploaded: the upload endpoint has its own tests, and what matters here is
        // only that the path resolves.
        config()->set('images.disk', self::DISK);
        Storage::fake(self::DISK);
    }

    private function uploaded(string $name): string
    {
        $path = 'uploads/'.$name;

        Storage::disk(self::DISK)->put($path, 'not really a jpeg, never decoded');

        return $path;
    }

    /**
     * Valid payload shared by the create/update tests.
     *
     * Carries a gallery because a product is not allowed without one, so a payload
     * without it would be testing the refusal rather than the endpoint.
     *
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Mechanical Keyboard',
            'description' => 'Hot-swappable 75% board.',
            'price' => 129.99,
            'stock' => 42,
            'images' => [['path' => $this->uploaded('keyboard.jpg')]],
        ], $overrides);
    }

    public function test_guest_cannot_reach_any_product_endpoint(): void
    {
        $product = Product::factory()->create();

        $this->getJson('/api/products')->assertUnauthorized();
        $this->getJson("/api/products/{$product->id}")->assertUnauthorized();
        $this->postJson('/api/products', $this->payload())->assertUnauthorized();
        $this->patchJson("/api/products/{$product->id}", ['stock' => 1])->assertUnauthorized();
        $this->deleteJson("/api/products/{$product->id}")->assertUnauthorized();
    }

    public function test_it_lists_products_paginated_newest_first(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        Product::factory()->count(3)->create();

        $response = $this->getJson('/api/products');

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.id', Product::max('id'))
            ->assertJsonStructure([
                'data' => [['id', 'name', 'description', 'price', 'stock', 'created_at', 'updated_at']],
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'per_page', 'total'],
            ]);
    }

    public function test_it_caps_the_page_size_at_one_hundred(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/products?per_page=5000')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);
    }

    public function test_it_falls_back_to_fifteen_per_page(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 15);
    }

    public function test_it_shows_a_single_product(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $product = Product::factory()->create(['name' => 'Standing Desk']);

        $this->getJson("/api/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonPath('data.name', 'Standing Desk');
    }

    public function test_it_returns_404_for_a_missing_product(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/products/999')->assertNotFound();
    }

    public function test_it_creates_a_product(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $response = $this->postJson('/api/products', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Mechanical Keyboard')
            ->assertJsonPath('data.price', 129.99)
            ->assertJsonPath('data.stock', 42);

        $this->assertDatabaseHas('products', [
            'name' => 'Mechanical Keyboard',
            'price' => 129.99,
            'stock' => 42,
        ]);
    }

    public function test_it_allows_a_null_description_on_create(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/products', $this->payload(['description' => null]))
            ->assertCreated()
            ->assertJsonPath('data.description', null);
    }

    public function test_it_rejects_a_create_with_missing_fields(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/products', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'price', 'stock']);

        $this->assertDatabaseCount('products', 0);
    }

    public function test_it_rejects_negative_price_and_stock(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/products', $this->payload(['price' => -1, 'stock' => -5]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['price', 'stock']);
    }

    public function test_it_rejects_a_price_with_more_than_two_decimals(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/products', $this->payload(['price' => '10.555']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['price']);
    }

    public function test_it_accepts_prices_with_fewer_than_two_decimals(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/products', $this->payload(['price' => 9.5]))
            ->assertCreated()
            ->assertJsonPath('data.price', 9.5);
    }

    public function test_it_updates_only_the_supplied_fields(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $product = Product::factory()->create([
            'name' => 'Old Name',
            'price' => 19.95,
            'stock' => 5,
        ]);

        $this->patchJson("/api/products/{$product->id}", ['stock' => 17])
            ->assertOk()
            ->assertJsonPath('data.name', 'Old Name')
            ->assertJsonPath('data.price', 19.95)
            ->assertJsonPath('data.stock', 17);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'name' => 'Old Name',
            'price' => 19.95,
            'stock' => 17,
        ]);
    }

    public function test_it_rejects_an_update_that_fails_validation_without_touching_the_record(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $product = Product::factory()->create(['name' => 'Old Name']);

        $this->patchJson("/api/products/{$product->id}", ['name' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'Old Name']);
    }

    public function test_it_deletes_a_product(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $product = Product::factory()->create();

        $this->deleteJson("/api/products/{$product->id}")->assertNoContent();

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }

    public function test_it_returns_404_when_deleting_a_missing_product(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->deleteJson('/api/products/999')->assertNotFound();
    }

    public function test_it_keeps_the_gallery_a_client_posted(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        // StoreProductRequest validated `images` for every caller, including this
        // one, while the controller wrote `$request->validated()` straight into
        // Product::create — where the column list dropped them. The request said
        // yes, the response said nothing, and the client's pictures were gone.
        $this->postJson('/api/products', $this->payload([
            'images' => [
                ['path' => $this->uploaded('front.jpg'), 'alt_text' => 'Tampak depan'],
                ['path' => $this->uploaded('side.jpg')],
            ],
        ]))->assertCreated();

        $product = Product::sole();

        $this->assertSame(
            ['uploads/front.jpg', 'uploads/side.jpg'],
            $product->images()->orderBy('sort_order')->pluck('path')->all(),
        );
    }

    public function test_it_returns_the_gallery_in_order(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $product = Product::factory()->create();
        $second = $product->images()->create(['path' => $this->uploaded('side.jpg'), 'sort_order' => 1]);
        $first = $product->images()->create(['path' => $this->uploaded('front.jpg'), 'sort_order' => 0]);

        $this->getJson("/api/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.images.0.id', $first->id)
            ->assertJsonPath('data.images.1.id', $second->id)
            ->assertJsonPath('data.images.0.path', 'uploads/front.jpg');
    }

    public function test_it_replaces_the_gallery_only_when_the_client_mentions_it(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $product = Product::factory()->create();
        $image = $product->images()->create(['path' => $this->uploaded('front.jpg'), 'sort_order' => 0]);

        // A stock update says nothing about the gallery, so the gallery stays. An
        // update that emptied it whenever `images` was absent would delete a
        // client's pictures on a request that never mentioned them.
        $this->patchJson("/api/products/{$product->id}", ['stock' => 5])->assertOk();

        $this->assertDatabaseHas('product_images', ['id' => $image->id]);

        // And the list a client does send replaces the whole gallery.
        $this->patchJson("/api/products/{$product->id}", [
            'images' => [['path' => $this->uploaded('side.jpg')]],
        ])->assertOk();

        $this->assertSame(1, ProductImage::count());
        $this->assertDatabaseHas('product_images', ['path' => 'uploads/side.jpg']);
        $this->assertDatabaseMissing('product_images', ['id' => $image->id]);
    }

    /**
     * An empty list on edit empties the gallery rather than being refused: a
     * product may end up with no picture. Absence, not emptiness, is what leaves
     * the gallery alone.
     */
    public function test_an_empty_gallery_empties_the_gallery(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $product = Product::factory()->create();
        $image = $product->images()->create(['path' => $this->uploaded('front.jpg'), 'sort_order' => 0]);

        $this->patchJson("/api/products/{$product->id}", ['images' => []])
            ->assertOk();

        $this->assertDatabaseMissing('product_images', ['id' => $image->id]);
    }

    /**
     * The same rule on the way in, on this door as well as the browser's: the
     * catalogue is written from two places and one of them must not be a way round
     * the rule.
     */
    public function test_it_allows_creating_a_product_with_no_gallery(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/products', $this->payload(['images' => []]))
            ->assertCreated();

        $this->assertDatabaseCount('products', 1);
    }

    public function test_it_refuses_a_gallery_path_the_disk_does_not_hold(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/products', $this->payload([
            'images' => [['path' => 'uploads/never-uploaded.jpg']],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('images.0.path');

        $this->assertSame(0, Product::count());
    }
}
