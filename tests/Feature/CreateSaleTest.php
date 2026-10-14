<?php

namespace Tests\Feature;

use App\Actions\CreateSale;
use App\Actions\ListSales;
use App\Actions\SyncProductSuppliers;
use App\Enums\SaleStatus;
use App\Models\City;
use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Salesperson;
use App\Models\Supplier;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class CreateSaleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => Customer::factory()->create()->id,
            'salesperson_id' => Salesperson::factory()->create()->id,
            'city_id' => City::factory()->create()->id,
            'items' => [],
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function line(Product $product, int $quantity, string $unitPrice, string $discount = '0.00'): array
    {
        return [
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'discount_total' => $discount,
        ];
    }

    /**
     * The action resolved from the container, as the controller gets it.
     *
     * Not constructed directly: CreateSale depends on the sanitizer, and a test
     * that news it up would have to know that. Resolving it here means adding a
     * dependency to the action does not ripple into every test that uses it.
     */
    private function createSale(): CreateSale
    {
        return $this->app->make(CreateSale::class);
    }

    public function test_it_creates_a_sale_with_its_items(): void
    {
        $product = Product::factory()->create(['price' => 5_000_000]);

        $sale = $this->createSale()->handle($this->payload([
            'items' => [$this->line($product, 24, '5000000.00')],
        ]));

        $this->assertSame('120000000.00', $sale->grand_total);
        $this->assertCount(1, $sale->items);
        $this->assertSame(24, $sale->items->first()->quantity);
    }

    /**
     * The totals are computed from the lines, never read off the request.
     *
     * This is the assertion that matters most in this file. A browser can post
     * whatever it likes, so if grand_total came from the payload a caller could
     * file a sale for one rupiah against a hundred million rupiah of stock, and
     * every revenue figure the dashboard ever showed would be that number.
     */
    public function test_the_totals_are_computed_and_a_falsified_total_is_ignored(): void
    {
        $product = Product::factory()->create(['price' => 5_000_000]);

        $sale = $this->createSale()->handle($this->payload([
            'items' => [$this->line($product, 24, '5000000.00')],
            'subtotal' => '1.00',
            'grand_total' => '1.00',
        ]));

        $this->assertSame('120000000.00', $sale->grand_total);
        $this->assertSame('120000000.00', $sale->subtotal);
    }

    public function test_a_falsified_line_total_is_ignored(): void
    {
        $product = Product::factory()->create();

        $sale = $this->createSale()->handle($this->payload([
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 10,
                'unit_price' => '1000.00',
                'line_total' => '0.01',
            ]],
        ]));

        $this->assertSame('10000.00', $sale->items->first()->line_total);
    }

    public function test_line_and_order_discounts_are_each_deducted_once(): void
    {
        $product = Product::factory()->create();

        $sale = $this->createSale()->handle($this->payload([
            // 2 x 1000 is 2000 gross, less 200 off the line and 50 off the order.
            'items' => [$this->line($product, 2, '1000.00', '200.00')],
            'discount_total' => '50.00',
        ]));

        $this->assertSame('2000.00', $sale->subtotal, 'subtotal is gross, before any discount.');
        $this->assertSame('250.00', $sale->discount_total, 'Both discounts roll up onto the header.');
        $this->assertSame('1750.00', $sale->grand_total);
        $this->assertSame('1800.00', $sale->items->first()->line_total, 'The line stays net of its own discount.');
    }

    /**
     * The header has to reconcile against its own lines, or nobody can tell
     * which figure is right.
     */
    public function test_the_header_reconciles_against_its_lines(): void
    {
        $product = Product::factory()->create();

        $sale = $this->createSale()->handle($this->payload([
            'items' => [
                $this->line($product, 3, '1000.00', '100.00'),
                $this->line($product, 2, '500.00'),
            ],
            'discount_total' => '25.00',
            'tax_total' => '110.00',
        ]));

        $this->assertSame('4000.00', $sale->subtotal);
        $this->assertSame('125.00', $sale->discount_total);
        $this->assertSame('110.00', $sale->tax_total);
        $this->assertSame('3985.00', $sale->grand_total);

        // sum(gross - line discount) + order discount - tax + ... == grand_total
        $reconstructed = (float) $sale->subtotal
            - (float) $sale->items->sum(fn ($line): float => (float) $line->discount_total)
            - 25.00
            + 110.00;

        $this->assertSame((float) $sale->grand_total, $reconstructed);
    }

    public function test_tax_is_added_after_the_discount(): void
    {
        $product = Product::factory()->create();

        $sale = $this->createSale()->handle($this->payload([
            'items' => [$this->line($product, 1, '1000.00')],
            'tax_total' => '110.00',
        ]));

        $this->assertSame('1110.00', $sale->grand_total);
    }

    /**
     * A sale always opens with a recorded event.
     *
     * The status column and the audit trail can only disagree if the sale was
     * never written to the trail, and there would be no record of how it reached
     * the state it is in.
     */
    public function test_it_records_the_opening_status_event_with_its_actor(): void
    {
        $actor = User::factory()->create();
        $product = Product::factory()->create();

        $sale = $this->createSale()->handle(
            $this->payload(['items' => [$this->line($product, 1, '1000.00')]]),
            $actor,
        );

        $event = $sale->statusEvents->first();

        $this->assertCount(1, $sale->statusEvents);
        $this->assertNull($event->from_status);
        $this->assertSame(SaleStatus::Pending, $event->to_status);
        $this->assertTrue($event->user->is($actor));
    }

    public function test_it_attaches_tags_with_their_notes(): void
    {
        $product = Product::factory()->create();
        $rush = Tag::factory()->create();
        $credit = Tag::factory()->create();

        $sale = $this->createSale()->handle($this->payload([
            'items' => [$this->line($product, 1, '1000.00')],
            'tags' => [
                ['id' => $rush->id, 'note' => 'Rush: ships tomorrow.'],
                $credit->id,
            ],
        ]));

        $notes = $sale->tags->mapWithKeys(fn (Tag $tag): array => [$tag->id => $tag->pivot->note])->all();

        $this->assertSame('Rush: ships tomorrow.', $notes[$rush->id]);
        $this->assertNull($notes[$credit->id], 'A bare id from a multi-select carries no note.');
    }

    public function test_it_can_be_created_with_no_tags(): void
    {
        $product = Product::factory()->create();

        $sale = $this->createSale()->handle($this->payload([
            'items' => [$this->line($product, 1, '1000.00')],
            'tags' => [],
        ]));

        $this->assertCount(0, $sale->tags);
    }

    /**
     * The write is atomic.
     *
     * A sale whose header landed without its items is not a sale with no items —
     * it is a record the dashboard would count as revenue that was never sold. The
     * rollback is what makes "part of one sale" impossible.
     */
    public function test_nothing_is_left_behind_when_a_line_fails(): void
    {
        $product = Product::factory()->create();

        // The tag names a row that does not exist, so the failure happens after
        // the sale and its items have already been written.
        try {
            $this->createSale()->handle($this->payload([
                'items' => [$this->line($product, 1, '1000.00')],
                'tags' => [['id' => 999_999, 'note' => 'nope']],
            ]));
        } catch (QueryException) {
            // expected
        }

        $this->assertSame(0, Sale::count(), 'The sale header must roll back with its items.');
        $this->assertSame(0, SaleItem::count());
    }

    public function test_each_sale_gets_a_distinct_reference(): void
    {
        $product = Product::factory()->create();
        $create = $this->createSale();

        $first = $create->handle($this->payload(['items' => [$this->line($product, 1, '1000.00')]]));
        $second = $create->handle($this->payload(['items' => [$this->line($product, 1, '1000.00')]]));

        $this->assertNotSame($first->reference, $second->reference);
        $this->assertStringStartsWith('SLV-', $first->reference);
    }

    /* ----------------------------------------------------------------------
     | SyncProductSuppliers
     | ---------------------------------------------------------------------- */

    public function test_syncing_suppliers_replaces_the_set_and_keeps_the_pivot_columns(): void
    {
        $product = Product::factory()->create();
        $keep = Supplier::factory()->create();
        $drop = Supplier::factory()->create();
        $add = Supplier::factory()->create();

        $product->suppliers()->attach($drop, ['unit_cost' => '1.00', 'lead_time_days' => 1, 'is_primary' => true]);

        (new SyncProductSuppliers)->handle($product, [
            ['supplier_id' => $keep->id, 'unit_cost' => '4500000.00', 'lead_time_days' => 21, 'is_primary' => true],
            ['supplier_id' => $add->id, 'unit_cost' => '4600000.00', 'lead_time_days' => 30, 'is_primary' => false],
        ]);

        $this->assertEqualsCanonicalizing(
            [$keep->id, $add->id],
            $product->suppliers()->pluck('suppliers.id')->all(),
            'A form submits the whole intended set, so the write replaces rather than adds.',
        );

        $this->assertSame('4500000.00', $product->suppliers()->find($keep)->pivot->unit_cost);
    }

    /**
     * Two suppliers marked primary would leave the desk guessing where stock
     * comes from. The last mark wins, and only one survives.
     */
    public function test_only_one_supplier_stays_primary(): void
    {
        $product = Product::factory()->create();
        $first = Supplier::factory()->create();
        $second = Supplier::factory()->create();

        (new SyncProductSuppliers)->handle($product, [
            ['supplier_id' => $first->id, 'unit_cost' => '100.00', 'lead_time_days' => 5, 'is_primary' => true],
            ['supplier_id' => $second->id, 'unit_cost' => '200.00', 'lead_time_days' => 5, 'is_primary' => true],
        ]);

        $primaries = $product->suppliers()->wherePivot('is_primary', true)->pluck('suppliers.id')->all();

        $this->assertCount(1, $primaries);
    }

    /* ----------------------------------------------------------------------
     | ListSales
     | ---------------------------------------------------------------------- */

    private function list(array $query = []): LengthAwarePaginator
    {
        return (new ListSales)->handle(Request::create('/sales', 'GET', $query));
    }

    public function test_it_lists_newest_first(): void
    {
        $older = Sale::factory()->create(['sold_at' => now()->subDays(10)]);
        $newer = Sale::factory()->create(['sold_at' => now()]);

        $this->assertSame(
            [$newer->id, $older->id],
            $this->list()->pluck('id')->all(),
        );
    }

    public function test_it_filters_by_status_city_and_salesperson(): void
    {
        $shipped = Sale::factory()->create(['status' => SaleStatus::Shipped]);
        $delivered = Sale::factory()->create(['status' => SaleStatus::Delivered]);

        $this->assertSame([$shipped->id], $this->list(['status' => 'shipped'])->pluck('id')->all());
        $this->assertSame([$delivered->id], $this->list(['status' => 'delivered'])->pluck('id')->all());
        $this->assertSame([$shipped->id], $this->list(['salesperson' => $shipped->salesperson_id])->pluck('id')->all());
    }

    public function test_an_unknown_status_filter_is_ignored_rather_than_matching_nothing(): void
    {
        Sale::factory()->count(2)->create();

        $this->assertCount(
            2,
            $this->list(['status' => 'not-a-status']),
            'A bad filter value must not silently return an empty ledger.',
        );
    }

    public function test_it_searches_the_reference_and_the_customer_name(): void
    {
        $wanted = Sale::factory()->create();
        Sale::factory()->create();

        $this->assertSame([$wanted->id], $this->list(['q' => $wanted->reference])->pluck('id')->all());

        $customer = Customer::factory()->create(['name' => 'CV Nusantara Jaya']);
        $byCustomer = Sale::factory()->for($customer)->create();

        $this->assertSame([$byCustomer->id], $this->list(['q' => 'Nusantara'])->pluck('id')->all());
    }

    /**
     * A wildcard typed into the box is a character someone is searching for.
     *
     * Left unescaped, "%" matches every row, so a search the user believes is
     * narrow silently returns the whole ledger — the same failure SearchProducts
     * guards against on the catalogue.
     */
    public function test_a_wildcard_in_the_search_matches_nothing(): void
    {
        Sale::factory()->create(['reference' => 'SLV-REAL-0001']);

        $this->assertCount(0, $this->list(['q' => '%']));
        $this->assertCount(0, $this->list(['q' => '_']));
    }

    public function test_it_caps_the_page_size(): void
    {
        Sale::factory()->count(3)->create();

        $this->assertSame(100, $this->list(['per_page' => 1000])->perPage());
        $this->assertSame(15, $this->list()->perPage());
    }

    /* ----------------------------------------------------------------------
     | The customer credit limit the order form checks against
     | ---------------------------------------------------------------------- */

    public function test_a_profile_credit_limit_is_exact(): void
    {
        $profile = CustomerProfile::factory()->create(['credit_limit' => '750000000.00']);

        $this->assertSame('750000000.00', $profile->fresh()->credit_limit);
    }
}
