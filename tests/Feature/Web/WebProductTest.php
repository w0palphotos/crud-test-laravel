<?php

namespace Tests\Feature\Web;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebProductTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_products_newest_first(): void
    {
        $this->actingAs(User::factory()->create());

        $older = Product::factory()->create(['name' => 'Older', 'created_at' => now()->subDay()]);
        $newer = Product::factory()->create(['name' => 'Newer', 'created_at' => now()]);

        $response = $this->get('/products')->assertOk();

        $response->assertSeeInOrder(['Newer', 'Older']);
        $this->assertStringNotContainsString($older->name.' stale', $response->getContent());
    }

    public function test_guests_cannot_reach_any_product_page(): void
    {
        $product = Product::factory()->create();

        $this->get('/products')->assertRedirect(route('login'));
        $this->get('/products/create')->assertRedirect(route('login'));
        $this->get("/products/{$product->id}/edit")->assertRedirect(route('login'));
    }

    public function test_it_creates_a_product(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->post('/products', [
            'name' => 'Cast Iron Skillet',
            'description' => 'Heavy and dependable.',
            'price' => '39.95',
            'stock' => 12,
        ]);

        $response->assertRedirect(route('products.index'));
        $response->assertSessionHas('status');

        $this->assertDatabaseHas('products', [
            'name' => 'Cast Iron Skillet',
            'price' => 39.95,
            'stock' => 12,
        ]);
    }

    public function test_it_reuses_the_api_validation_rules(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post('/products', [
            'name' => '',
            'price' => 'not-a-number',
            'stock' => -1,
        ])->assertSessionHasErrors(['name', 'price', 'stock']);

        $this->assertDatabaseCount('products', 0);
    }

    public function test_it_rejects_a_price_with_more_than_two_decimals(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post('/products', [
            'name' => 'Odd pricing',
            'price' => '1.234',
            'stock' => 1,
        ])->assertSessionHasErrors('price');
    }

    public function test_it_renders_the_create_form(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/products/create')->assertOk()->assertSee('Create product');
    }

    public function test_it_updates_a_product(): void
    {
        $this->actingAs(User::factory()->create());
        $product = Product::factory()->create(['name' => 'Old name', 'stock' => 1]);

        $response = $this->patch("/products/{$product->id}", [
            'name' => 'New name',
            'description' => $product->description,
            'price' => $product->price,
            'stock' => 5,
        ]);

        $response->assertRedirect(route('products.index'));

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'name' => 'New name',
            'stock' => 5,
        ]);
    }

    public function test_it_deletes_a_product(): void
    {
        $this->actingAs(User::factory()->create());
        $product = Product::factory()->create();

        $this->delete("/products/{$product->id}")->assertRedirect(route('products.index'));

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }

    public function test_it_filters_by_name(): void
    {
        $this->actingAs(User::factory()->create());
        Product::factory()->create(['name' => 'Cast Iron Skillet']);
        Product::factory()->create(['name' => 'Copper Saucepan']);

        $response = $this->get('/products?q=skillet')->assertOk();

        $response->assertSee('Cast Iron Skillet');
        $response->assertDontSee('Copper Saucepan');
    }

    public function test_the_search_survives_pagination(): void
    {
        $this->actingAs(User::factory()->create());
        Product::factory()->count(20)->create(['name' => 'Skillet variant']);

        $response = $this->get('/products?q=skillet&per_page=5')->assertOk();

        $this->assertStringContainsString('q=skillet', $response->getContent());
    }

    public function test_it_paginates(): void
    {
        $this->actingAs(User::factory()->create());
        Product::factory()->count(20)->create();

        $response = $this->get('/products?per_page=5')->assertOk();

        $this->assertSame(5, $response->viewData('products')->perPage());
        $this->assertSame(20, $response->viewData('products')->total());
    }

    public function test_it_caps_the_page_size(): void
    {
        $this->actingAs(User::factory()->create());
        Product::factory()->count(3)->create();

        $this->get('/products?per_page=1000')
            ->assertOk()
            ->assertViewHas('products', fn ($products) => $products->perPage() === 100);
    }

    public function test_an_empty_state_is_shown_when_there_are_no_products(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/products')->assertOk()->assertSee('No products yet.');
    }
}
