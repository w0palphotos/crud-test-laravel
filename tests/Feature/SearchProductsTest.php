<?php

namespace Tests\Feature;

use App\Ai\Agents\Assistant;
use App\Ai\Tools\SearchProducts;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

/**
 * This tool is the only path from a user's question to the database, so its
 * boundaries are what keep a model from reading or guessing more than it should.
 * The model supplies these arguments, which means every one of them is
 * model-controlled input and has to be treated as such.
 */
class SearchProductsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function search(array $arguments = []): string
    {
        return (new SearchProducts)->handle(new Request($arguments));
    }

    /**
     * The JSON payload of a tool result, dropping the leading count line.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(string $result): array
    {
        return json_decode(substr($result, strpos($result, '[')), true);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $result): array
    {
        return $this->rows($result);
    }

    private function product(string $name, float $price, int $stock, string $description = ''): Product
    {
        return Product::factory()->create([
            'name' => $name,
            'price' => $price,
            'stock' => $stock,
            'description' => $description,
        ]);
    }

    public function test_with_no_arguments_it_lists_the_catalogue(): void
    {
        $this->product('at delectus sunt', 105.36, 136);
        $this->product('libero quaerat ut', 77.74, 9999);

        $result = $this->search();

        $this->assertStringContainsString('at delectus sunt', $result);
        $this->assertStringContainsString('libero quaerat ut', $result);
        $this->assertStringContainsString('Found 2 product(s).', $result);
    }

    public function test_it_returns_the_columns_the_assistant_needs(): void
    {
        $this->product('suscipit accusantium', 149.02, 98, 'Qui beatae sed quas.');

        // The result is a one-line count followed by the JSON payload.
        $decoded = $this->rows($this->search());

        $this->assertSame(
            ['id', 'name', 'description', 'price', 'stock'],
            array_keys($decoded[0]),
            'Returning anything else widens what the model can see.',
        );
    }

    public function test_a_keyword_matches_the_name(): void
    {
        $this->product('eveniet adipisci voluptatum', 361.16, 53);
        $this->product('mollitia porro rerum', 7.67, 39);

        $result = $this->search(['keyword' => 'mollitia']);

        $this->assertStringContainsString('mollitia porro rerum', $result);
        $this->assertStringNotContainsString('eveniet adipisci', $result);
    }

    public function test_a_keyword_matches_the_description_too(): void
    {
        $this->product('voluptas omnis magni', 355.53, 19, 'Omnis nihil harum fuga.');

        $this->assertStringContainsString('voluptas omnis magni', $this->search(['keyword' => 'harum']));
    }

    /**
     * A LIKE wildcard is not a literal character. Unescaped, a single % from the
     * model matches every row, so a filter that reads as narrow returns the whole
     * catalogue and the assistant answers a different question than the one asked.
     *
     * Asserted in SearchProductsPostgresTest rather than here: SQLite's LIKE has
     * no default escape character, so the behaviour that matters is only observable
     * on the engine production actually runs.
     */
    public function test_like_escaping_is_asserted_against_postgres(): void
    {
        $this->assertFileExists(
            __DIR__.'/SearchProductsPostgresTest.php',
            'The escaping assertions belong to the Postgres suite.'
        );
    }

    public function test_max_price_filters_by_price(): void
    {
        $this->product('cheap', 10.00, 5);
        $this->product('pricey', 900.00, 5);

        $result = $this->search(['max_price' => 100]);

        $this->assertStringContainsString('cheap', $result);
        $this->assertStringNotContainsString('pricey', $result);
    }

    public function test_in_stock_only_filters_out_empty_stock(): void
    {
        $this->product('tersedia', 20.00, 12);
        $this->product('habis', 20.00, 0);

        $result = $this->search(['in_stock_only' => true]);

        $this->assertStringContainsString('tersedia', $result);
        $this->assertStringNotContainsString('habis', $result);
    }

    public function test_out_of_stock_only_filters_the_other_way(): void
    {
        $this->product('tersedia', 20.00, 12);
        $this->product('habis', 20.00, 0);

        $result = $this->search(['out_of_stock_only' => true]);

        $this->assertStringContainsString('habis', $result);
        $this->assertStringNotContainsString('tersedia', $result);
    }

    public function test_it_says_so_plainly_when_nothing_matches(): void
    {
        $this->product('sunt consequatur ratione', 375.45, 97);

        $result = $this->search(['keyword' => 'tidak-ada-seperti-ini']);

        $this->assertStringContainsString('No products matched', $result);
        $this->assertStringContainsString('rather than inventing one', $result);
    }

    /**
     * An absent max_price must mean "no limit", not "free". Reading it through
     * float() returns 0.0 for a missing key, which is a valid price filter and
     * silently matches nothing at all, so the whole catalogue appears empty.
     */
    public function test_an_absent_max_price_does_not_filter(): void
    {
        $this->product('tidakmurah', 105.36, 136);

        $this->assertStringContainsString('tidakmurah', $this->search(['keyword' => 'tidakmurah']));
    }

    public function test_an_explicit_zero_max_price_is_honoured_as_a_limit(): void
    {
        $this->product('gratis', 0.00, 5);
        $this->product('bayar', 105.36, 5);

        $result = $this->search(['max_price' => 0]);

        $this->assertStringContainsString('gratis', $result);
        $this->assertStringNotContainsString('bayar', $result);
    }

    /**
     * The cap has to be visible. A silent truncation would let the assistant
     * report the whole catalogue having seen a fragment of it.
     */
    public function test_a_truncated_result_says_so(): void
    {
        Product::factory()->count(30)->create();

        $result = $this->search();

        $this->assertStringContainsString('Showing the first 25 products', $result);
        $this->assertStringContainsString('there may be more', $result);
    }

    public function test_the_cap_is_not_exceeded(): void
    {
        Product::factory()->count(30)->create();

        $this->assertCount(25, $this->rows($this->search()));
    }

    /**
     * Every filter is a bound parameter on a named column. The model cannot choose
     * a column or a table, so there is no identifier for a crafted argument to
     * escape through. This asserts the schema keeps that surface closed.
     */
    public function test_the_schema_exposes_only_filters(): void
    {
        $schema = (new SearchProducts)->schema(app(JsonSchemaTypeFactory::class));

        $this->assertSame(
            ['keyword', 'max_price', 'in_stock_only', 'out_of_stock_only'],
            array_keys($schema),
        );

        $serialised = json_encode(array_map(fn ($type) => $type->toArray(), $schema));

        foreach (['table', 'column', 'sql', 'query', 'select'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialised, "The schema must not accept a '{$forbidden}'.");
        }
    }

    public function test_the_agent_exposes_exactly_this_one_tool(): void
    {
        $tools = iterator_to_array((function () {
            foreach ((new Assistant)->tools() as $tool) {
                yield $tool;
            }
        })());

        $this->assertCount(1, $tools);
        $this->assertInstanceOf(SearchProducts::class, $tools[0]);
    }
}
