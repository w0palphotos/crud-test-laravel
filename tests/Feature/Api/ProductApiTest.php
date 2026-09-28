<?php

namespace Tests\Feature\Api;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Valid payload shared by the create/update tests.
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
        Sanctum::actingAs(User::factory()->create());

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
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/products?per_page=5000')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);
    }

    public function test_it_falls_back_to_fifteen_per_page(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 15);
    }

    public function test_it_shows_a_single_product(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $product = Product::factory()->create(['name' => 'Standing Desk']);

        $this->getJson("/api/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonPath('data.name', 'Standing Desk');
    }

    public function test_it_returns_404_for_a_missing_product(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/products/999')->assertNotFound();
    }

    public function test_it_creates_a_product(): void
    {
        Sanctum::actingAs(User::factory()->create());

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
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/products', $this->payload(['description' => null]))
            ->assertCreated()
            ->assertJsonPath('data.description', null);
    }

    public function test_it_rejects_a_create_with_missing_fields(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/products', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'price', 'stock']);

        $this->assertDatabaseCount('products', 0);
    }

    public function test_it_rejects_negative_price_and_stock(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/products', $this->payload(['price' => -1, 'stock' => -5]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['price', 'stock']);
    }

    public function test_it_rejects_a_price_with_more_than_two_decimals(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/products', $this->payload(['price' => '10.555']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['price']);
    }

    public function test_it_accepts_prices_with_fewer_than_two_decimals(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/products', $this->payload(['price' => 9.5]))
            ->assertCreated()
            ->assertJsonPath('data.price', 9.5);
    }

    public function test_it_updates_only_the_supplied_fields(): void
    {
        Sanctum::actingAs(User::factory()->create());

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
        Sanctum::actingAs(User::factory()->create());

        $product = Product::factory()->create(['name' => 'Old Name']);

        $this->patchJson("/api/products/{$product->id}", ['name' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'Old Name']);
    }

    public function test_it_deletes_a_product(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $product = Product::factory()->create();

        $this->deleteJson("/api/products/{$product->id}")->assertNoContent();

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }

    public function test_it_returns_404_when_deleting_a_missing_product(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson('/api/products/999')->assertNotFound();
    }
}
