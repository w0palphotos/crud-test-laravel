<?php

namespace Tests\Feature\Web;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class WebProductTest extends TestCase
{
    use RefreshDatabase;

    private const DISK = 'images';

    protected function setUp(): void
    {
        parent::setUp();

        // A product may not be created without a gallery, and a gallery row naming
        // a file is only accepted if the disk is actually holding it — which is the
        // whole point of the check. So a test that creates a product needs a disk
        // with something on it.
        config()->set('images.disk', self::DISK);
        Storage::fake(self::DISK);
    }

    private function uploaded(string $name = 'product.jpg'): string
    {
        $path = 'uploads/'.$name;

        Storage::disk(self::DISK)->put($path, 'not really a jpeg, never decoded');

        return $path;
    }

    public function test_it_lists_products_newest_first(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $older = Product::factory()->create(['name' => 'Older', 'created_at' => now()->subDay()]);
        $newer = Product::factory()->create(['name' => 'Newer', 'created_at' => now()]);

        // Ordering is still ListProducts' decision. The page is a Vue component
        // now, so the rows are asserted where the data actually is: the page
        // props Inertia serialises into the browser.
        $this->get('/products')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Products/Index')
                ->where('products.data.0.name', 'Newer')
                ->where('products.data.1.name', 'Older')
            );
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
        $this->actingAs(User::factory()->admin()->create());

        $response = $this->post('/products', [
            'name' => 'Cast Iron Skillet',
            'description' => 'Heavy and dependable.',
            'price' => '39.95',
            'stock' => 12,
            'images' => [['path' => $this->uploaded('skillet.jpg')]],
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
        $this->actingAs(User::factory()->admin()->create());

        $this->post('/products', [
            'name' => '',
            'price' => 'not-a-number',
            'stock' => -1,
        ])->assertSessionHasErrors(['name', 'price', 'stock']);

        $this->assertDatabaseCount('products', 0);
    }

    public function test_it_rejects_a_price_with_more_than_two_decimals(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->post('/products', [
            'name' => 'Odd pricing',
            'price' => '1.234',
            'stock' => 1,
        ])->assertSessionHasErrors('price');
    }

    /**
     * An image url names a path, not a host.
     *
     * Built from APP_URL it was correct only while APP_URL matched the host the
     * browser was actually on, and the two disagree silently: a deployment with
     * APP_URL left at localhost serves every thumbnail pointing at localhost,
     * which fails as a broken image long before it reads as a misconfiguration.
     * Root-relative, the browser resolves it against whatever is serving it.
     */
    public function test_an_image_url_names_a_path_and_not_a_host(): void
    {
        config()->set('images.disk', self::DISK);

        $image = Product::factory()->create()->images()->create([
            'path' => $this->uploaded('thumbnail.jpg'),
        ]);

        $this->assertStringStartsWith('/storage/uploads/', $image->url);
        $this->assertStringNotContainsString('localhost', $image->url);
        $this->assertStringNotContainsString('://', $image->url);
    }

    /**
     * The same guarantee with an APP_URL that disagrees with everything else.
     *
     * The point is not that a wrong APP_URL is fine — it is not, route() uses it
     * for every form's endpoint — but that the image url no longer reads it at
     * all, so one wrong value cannot silently move every picture on the site.
     */
    public function test_an_image_url_ignores_a_host_that_would_otherwise_leak_into_it(): void
    {
        config()->set('images.disk', self::DISK);
        config()->set('app.url', 'http://localhost:8000');

        $image = Product::factory()->create()->images()->create([
            'path' => $this->uploaded('drift.jpg'),
        ]);

        $this->assertSame('/storage/uploads/drift.jpg', $image->url);
    }

    public function test_it_renders_the_create_form(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get('/products/create')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Products/Create'));
    }

    public function test_it_updates_a_product(): void
    {
        $this->actingAs(User::factory()->admin()->create());
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
        $this->actingAs(User::factory()->admin()->create());
        $product = Product::factory()->create();

        $this->delete("/products/{$product->id}")->assertRedirect(route('products.index'));

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }

    public function test_it_filters_by_name(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        Product::factory()->create(['name' => 'Cast Iron Skillet']);
        Product::factory()->create(['name' => 'Copper Saucepan']);

        $this->get('/products?q=skillet')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('products.data', 1)
                ->where('products.data.0.name', 'Cast Iron Skillet')
                ->where('products.total', 1)
                ->where('search', 'skillet')
            );
    }

    /**
     * Each row carries the URLs its own links and delete form need.
     *
     * route() is a Blade helper a Vue component cannot reach, so the controller
     * builds them. That is the reason this exists: a component reconstructing a
     * path by string concatenation would be a second place to change every time
     * a route moved, and the failure mode is a link that looks right and 404s.
     */
    public function test_each_row_carries_the_urls_its_links_need(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $product = Product::factory()->create(['name' => 'Cast Iron Skillet']);

        $this->get('/products')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('products.data.0.name', 'Cast Iron Skillet')
                ->where('products.data.0.edit_url', route('products.edit', $product))
                ->where('products.data.0.destroy_url', route('products.destroy', $product))
            );
    }

    public function test_the_search_survives_pagination(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        Product::factory()->count(20)->create(['name' => 'Skillet variant']);

        $response = $this->get('/products?q=skillet&per_page=5')->assertOk();

        $this->assertStringContainsString('q=skillet', $response->getContent());
    }

    public function test_it_paginates(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        Product::factory()->count(20)->create();

        $this->get('/products?per_page=5')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('products.per_page', 5)
                ->where('products.total', 20)
                ->has('products.data', 5)
            );
    }

    public function test_it_caps_the_page_size(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        Product::factory()->count(3)->create();

        $this->get('/products?per_page=1000')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('products.per_page', 100));
    }

    /**
     * An empty result still reaches the page as an empty set of rows rather than
     * as markup. The sentence the reader sees is now Vue's to render, so what is
     * pinned here is that the page was handed nothing to render, which is what
     * makes that sentence correct.
     */
    public function test_the_page_is_handed_no_rows_when_there_are_no_products(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get('/products')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('products.data', 0)
                ->where('products.total', 0)
                ->where('search', '')
            );
    }

    public function test_a_product_with_sales_cannot_be_deleted(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $product = Product::factory()->create();
        $sale = Sale::factory()->create();
        SaleItem::factory()->create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
        ]);

        $response = $this->delete("/products/{$product->id}");

        $response->assertRedirect('/products');
        $response->assertSessionHas('error');
        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }
}
