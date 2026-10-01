<?php

namespace Tests\Feature;

use App\Actions\ListProducts;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * The API and the web UI both list products, and they used to express "newest
 * first, 15 per page, capped at 100" independently. Nothing stopped one side being
 * changed while the other was not, and the symptom would be two pages of the same
 * data disagreeing, which is easy to miss and annoying to debug.
 *
 * These assert the shared rule directly rather than comparing two HTTP responses,
 * so a failure names the rule that broke.
 */
class ListProductsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_orders_newest_first(): void
    {
        $oldest = Product::factory()->create(['created_at' => now()->subDays(2)]);
        $middle = Product::factory()->create(['created_at' => now()->subDay()]);
        $newest = Product::factory()->create(['created_at' => now()]);

        $ids = $this->list()->pluck('id')->all();

        $this->assertSame([$newest->id, $middle->id, $oldest->id], $ids);
    }

    public function test_it_defaults_to_fifteen_per_page(): void
    {
        Product::factory()->count(20)->create();

        $this->assertSame(15, $this->list()->perPage());
    }

    public function test_it_caps_the_page_size_at_one_hundred(): void
    {
        $this->assertSame(100, $this->list(['per_page' => 1000])->perPage());
    }

    public function test_it_floors_the_page_size_at_one(): void
    {
        $this->assertSame(1, $this->list(['per_page' => 0])->perPage());
        $this->assertSame(1, $this->list(['per_page' => -5])->perPage());
    }

    public function test_the_documented_bounds_match_the_implementation(): void
    {
        // The OpenAPI docblock on ProductController::index references these
        // constants, so the spec cannot advertise a different range.
        $this->assertSame(15, ListProducts::DEFAULT_PER_PAGE);
        $this->assertSame(100, ListProducts::MAX_PER_PAGE);
    }

    public function test_a_search_filters_by_name(): void
    {
        Product::factory()->create(['name' => 'Cast Iron Skillet']);
        Product::factory()->create(['name' => 'Copper Saucepan']);

        $results = $this->list(search: 'skillet');

        $this->assertSame(1, $results->total());
        $this->assertStringContainsString('Skillet', $results->first()->name);
    }

    public function test_a_search_is_trimmed_and_an_empty_search_lists_everything(): void
    {
        Product::factory()->count(3)->create();

        $this->assertSame(3, $this->list(search: '   ')->total());
        $this->assertSame(3, $this->list()->total());
    }

    public function test_a_search_keeps_the_newest_first_ordering(): void
    {
        Product::factory()->create(['name' => 'Skillet small', 'created_at' => now()->subDay()]);
        Product::factory()->create(['name' => 'Skillet large', 'created_at' => now()]);

        $ids = $this->list(search: 'skillet')->pluck('id')->all();

        $this->assertCount(2, $ids);

        $newest = Product::where('name', 'Skillet large')->value('id');
        $this->assertSame($newest, $ids[0], 'The newest match must come first.');
    }

    /**
     * The two surfaces read the same rows, so the web page and the JSON listing
     * must agree. This is the assertion that would have failed before the action
     * was extracted.
     */
    public function test_both_surfaces_agree(): void
    {
        $this->actingAs(User::factory()->create());
        Product::factory()->count(20)->create();

        $web = $this->get('/products?per_page=5')
            ->assertOk()
            ->viewData('products');

        $api = $this->actingAs(User::factory()->create())
            ->getJson('/api/products?per_page=5')
            ->assertOk()
            ->json('data.*.id');

        // json('data.*.id') yields ints and the paginator yields strings, so
        // compare as integers on both sides rather than casting one into the other's
        // shape, which would hide a genuine difference.
        $this->assertSame(
            array_map('intval', $api),
            $web->pluck('id')->map('intval')->all(),
        );
    }

    /**
     * @param  array<string, int>  $query
     */
    private function list(array $query = [], ?string $search = null)
    {
        $request = Request::create('/products', 'GET', $query);

        return app(ListProducts::class)->handle($request, $search);
    }
}
