<?php

namespace Tests\Feature;

use App\Ai\Tools\SearchProducts;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

/**
 * The LIKE-escaping half of SearchProductsTest, against Postgres.
 *
 * This behaviour cannot be asserted on SQLite: its LIKE has no default escape
 * character, so an escaped "%" there matches nothing at all, while on Postgres,
 * which is what production runs, the same pattern matches the literal percent.
 * Asserting it on SQLite would test the wrong engine and pass or fail for reasons
 * that say nothing about production.
 *
 * The default suite runs on SQLite, so every test here skips itself unless it is
 * pointed at Postgres. To run it:
 *
 *     DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=postgres \
 *     DB_USERNAME=postgres DB_PASSWORD=postgres DB_URL= \
 *     APP_ENV=testing CACHE_STORE=array SESSION_DRIVER=array \
 *     php artisan test tests/Feature/SearchProductsPostgresTest.php
 *
 * @group pgsql
 */
class SearchProductsPostgresTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Needs Postgres: SQLite LIKE has no default escape character.');
        }
    }

    private function search(array $arguments = []): string
    {
        return (new SearchProducts)->handle(new Request($arguments));
    }

    private function product(string $name, float $price = 100.00, int $stock = 10): Product
    {
        return Product::factory()->create(['name' => $name, 'price' => $price, 'stock' => $stock]);
    }

    /**
     * A LIKE wildcard is not a literal character. Unescaped, a lone % matches every
     * row, so a search the assistant believes is narrow silently returns the whole
     * catalogue and the answer describes something the user never asked about.
     */
    public function test_a_wildcard_keyword_matches_nothing(): void
    {
        $this->product('eius distinctio repellat', 135.40, 92);
        $this->product('libero quaerat ut', 77.74, 40);

        $this->assertStringContainsString('No products matched', $this->search(['keyword' => '%']));
        $this->assertStringContainsString('No products matched', $this->search(['keyword' => '_']));
    }

    /**
     * Escaping must not break an ordinary term that happens to contain a percent.
     */
    public function test_a_literal_percent_in_a_search_term_still_matches(): void
    {
        $this->product('diskon 50% off');

        $this->assertStringContainsString('diskon 50% off', $this->search(['keyword' => '50%']));
    }

    public function test_an_ordinary_term_is_unaffected_by_escaping(): void
    {
        $this->product('eveniet adipisci voluptatum', 361.16, 53);
        $this->product('mollitia porro rerum', 7.67, 39);

        $this->assertStringContainsString('mollitia porro rerum', $this->search(['keyword' => 'mollitia']));
        $this->assertStringNotContainsString('eveniet adipisci', $this->search(['keyword' => 'mollitia']));
    }

    /**
     * An absent max_price must mean "no limit". Reading it through float() returns
     * 0.0 for a missing key, which is a real price filter and hides every product.
     */
    public function test_an_absent_max_price_returns_everything(): void
    {
        $this->product('sunt consequatur ratione', 375.45, 97);
        $this->product('corporis ut eaque', 165.02, 152);

        $result = $this->search();

        $this->assertStringContainsString('sunt consequatur ratione', $result);
        $this->assertStringContainsString('corporis ut eaque', $result);
        $this->assertStringContainsString('Found 2 product(s).', $result);
    }

    public function test_the_decimal_price_filter_works_on_postgres(): void
    {
        $this->product('mahal', 361.16, 10);
        $this->product('murah', 77.74, 10);

        $result = $this->search(['max_price' => 100]);

        $this->assertStringContainsString('murah', $result);
        $this->assertStringNotContainsString('mahal', $result);
    }
}
