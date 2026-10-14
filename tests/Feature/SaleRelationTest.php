<?php

namespace Tests\Feature;

use App\Enums\SaleStatus;
use App\Models\Category;
use App\Models\City;
use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Salesperson;
use App\Models\SaleStatusEvent;
use App\Models\Supplier;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The relationships in the sales desk schema, asserted as relationships.
 *
 * The point is not that the rows exist — any foreign key proves that — but that
 * each shape behaves the way its type promises: the one-to-ones refuse a second
 * row, the many-to-many pivots carry and keep their extra columns, and the
 * restrictOnDelete foreign keys stop a deletion that would leave a record
 * describing something that no longer exists.
 */
class SaleRelationTest extends TestCase
{
    use RefreshDatabase;

    /* ----------------------------------------------------------------------
     | One-to-one
     | ---------------------------------------------------------------------- */

    public function test_a_customer_has_one_profile_and_one_profile_belongs_to_one_customer(): void
    {
        $customer = Customer::factory()->create();
        $profile = CustomerProfile::factory()->for($customer)->create(['credit_limit' => '250000.00']);

        $this->assertTrue($customer->profile->is($profile));
        $this->assertTrue($profile->customer->is($customer));
        $this->assertSame('250000.00', $profile->credit_limit);
    }

    /**
     * The one-to-one is enforced by the database, not by convention.
     *
     * Two concurrent requests could both pass an application-level "does this
     * customer have a profile yet" check before either wrote. The unique index is
     * what actually makes it one.
     */
    public function test_a_customer_cannot_have_a_second_profile(): void
    {
        $customer = Customer::factory()->create();
        CustomerProfile::factory()->for($customer)->create();

        $this->expectException(QueryException::class);

        CustomerProfile::factory()->for($customer)->create();
    }

    public function test_a_sale_has_one_payment_and_a_payment_belongs_to_one_sale(): void
    {
        $sale = Sale::factory()->create();
        $payment = Payment::factory()->for($sale)->create();

        $this->assertTrue($sale->payment->is($payment));
        $this->assertTrue($payment->sale->is($sale));
    }

    /**
     * A second settlement against one sale is not an update. It is a second sale
     * against a credit note, which gets its own row.
     */
    public function test_a_sale_cannot_have_a_second_payment(): void
    {
        $sale = Sale::factory()->create();
        Payment::factory()->for($sale)->create();

        $this->expectException(QueryException::class);

        Payment::factory()->for($sale)->create();
    }

    /* ----------------------------------------------------------------------
     | One-to-many
     | ---------------------------------------------------------------------- */

    public function test_a_customer_many_sales_and_each_sale_has_one_customer(): void
    {
        $customer = Customer::factory()->create();
        $sales = Sale::factory()->count(3)->for($customer)->create();

        $this->assertCount(3, $customer->sales);

        foreach ($sales as $sale) {
            $this->assertTrue($sale->customer->is($customer));
        }
    }

    public function test_a_salesperson_a_city_and_a_product_each_have_many_sales(): void
    {
        $salesperson = Salesperson::factory()->create();
        $city = City::factory()->create();
        $product = Product::factory()->create();

        Sale::factory()->count(2)->for($salesperson)->for($city)->create();
        SaleItem::factory()->count(2)->for($product)->create();

        $this->assertCount(2, $salesperson->sales);
        $this->assertCount(2, $city->sales);
        $this->assertCount(2, $product->saleItems);
    }

    public function test_a_sale_has_many_items_and_an_item_belongs_to_one_sale(): void
    {
        $sale = Sale::factory()->create();
        $items = SaleItem::factory()->count(3)->for($sale)->create();

        $this->assertCount(3, $sale->items);

        foreach ($items as $item) {
            $this->assertTrue($item->sale->is($sale));
        }
    }

    public function test_a_product_has_many_images_ordered_for_display(): void
    {
        $product = Product::factory()->create();

        ProductImage::factory()->for($product)->create(['sort_order' => 2, 'path' => 'second.jpg']);
        ProductImage::factory()->for($product)->create(['sort_order' => 0, 'path' => 'first.jpg']);

        $this->assertSame(
            ['first.jpg', 'second.jpg'],
            $product->images->pluck('path')->all(),
            'A gallery has to render in its declared order, not in insertion order.',
        );
    }

    /**
     * The status history is a has-many through a nullable user.
     *
     * The person who made a change can leave. The change itself must not go with
     * them, or a sale ends up in a state with no recorded explanation.
     */
    public function test_a_status_event_survives_the_user_who_made_it_being_deleted(): void
    {
        $sale = Sale::factory()->create();
        $user = User::factory()->create();

        $event = SaleStatusEvent::factory()->for($sale)->create(['user_id' => $user->id]);
        $this->assertTrue($event->user->is($user));

        $user->delete();

        $this->assertDatabaseHas('sale_status_events', [
            'id' => $event->id,
            'sale_id' => $sale->id,
        ]);
        $this->assertNull($event->fresh()->user);
    }

    /**
     * A line is the record of what a customer paid. Deleting the catalogue entry
     * must not be able to orphan it.
     */
    public function test_a_product_in_a_sale_cannot_be_deleted(): void
    {
        $item = SaleItem::factory()->create();

        $this->expectException(QueryException::class);

        $item->product->delete();
    }

    /* ----------------------------------------------------------------------
     | Many-to-many: plain
     | ---------------------------------------------------------------------- */

    public function test_a_category_and_a_product_share_a_plain_pivot(): void
    {
        $category = Category::factory()->create();
        $product = Product::factory()->create();

        $category->products()->attach($product);

        $this->assertTrue($category->products->first()->is($product));
        $this->assertTrue($product->categories->first()->is($category));
    }

    /**
     * The pair is the identity of the row. The composite primary key is what
     * stops the same pairing being attached twice.
     */
    public function test_the_same_pairing_cannot_be_attached_twice(): void
    {
        $category = Category::factory()->create();
        $product = Product::factory()->create();

        $category->products()->attach($product);

        $this->expectException(QueryException::class);

        $category->products()->attach($product);
    }

    public function test_sync_replaces_the_whole_set_rather_than_adding_to_it(): void
    {
        $first = Category::factory()->create();
        $second = Category::factory()->create();
        $third = Category::factory()->create();

        $product = Product::factory()->create();
        $product->categories()->attach([$first->id, $second->id]);

        $product->categories()->sync([$first->id, $third->id]);

        $this->assertEqualsCanonicalizing(
            [$first->id, $third->id],
            $product->categories()->pluck('categories.id')->all(),
            'sync() is a replacement. attach() would have left the removed category in place.',
        );
    }

    public function test_detaching_removes_only_the_named_pairing(): void
    {
        $keep = Category::factory()->create();
        $drop = Category::factory()->create();

        $product = Product::factory()->create();
        $product->categories()->attach([$keep->id, $drop->id]);

        $product->categories()->detach($drop);

        $this->assertSame([$keep->id], $product->categories()->pluck('categories.id')->all());
    }

    /* ----------------------------------------------------------------------
     | Many-to-many: carrying data
     | ---------------------------------------------------------------------- */

    public function test_a_supplier_pairing_keeps_its_cost_and_lead_time(): void
    {
        $product = Product::factory()->create();
        $supplier = Supplier::factory()->create();

        $product->suppliers()->attach($supplier, [
            'unit_cost' => '4500000.00',
            'lead_time_days' => 21,
            'is_primary' => true,
        ]);

        $attached = $product->suppliers()->first();

        $this->assertTrue($attached->is($supplier));
        $this->assertSame('4500000.00', $attached->pivot->unit_cost);
        $this->assertSame(21, $attached->pivot->lead_time_days);
        $this->assertTrue((bool) $attached->pivot->is_primary);
    }

    /**
     * The reason these columns are on the pivot and not on either model: the same
     * product, from two suppliers, at two costs and two lead times. Forcing one
     * of those into a single column would make one of the two facts a lie.
     */
    public function test_one_product_can_be_supplied_by_several_at_different_prices(): void
    {
        $product = Product::factory()->create();
        $cheap = Supplier::factory()->create();
        $fast = Supplier::factory()->create();

        $product->suppliers()->attach($cheap, ['unit_cost' => '4000000.00', 'lead_time_days' => 30, 'is_primary' => true]);
        $product->suppliers()->attach($fast, ['unit_cost' => '4800000.00', 'lead_time_days' => 7, 'is_primary' => false]);

        // Read through the models rather than pluck()ing the column: a pluck is
        // raw SQL and would hand back the driver's float, which is exactly the
        // value the pivot model's cast exists to prevent.
        $costs = $product->suppliers()->get()
            ->map(fn (Supplier $supplier): string => $supplier->pivot->unit_cost)
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['4000000.00', '4800000.00'], $costs);
    }

    public function test_a_tag_pairing_keeps_its_note(): void
    {
        $sale = Sale::factory()->create();
        $tag = Tag::factory()->create();

        $sale->tags()->attach($tag, ['note' => 'Rush: ships before the promotion.']);

        $this->assertSame('Rush: ships before the promotion.', $sale->tags()->first()->pivot->note);
    }

    /* ----------------------------------------------------------------------
     | The status machine
     | ---------------------------------------------------------------------- */

    public function test_the_status_lifecycle_allows_the_documented_moves(): void
    {
        $this->assertTrue(SaleStatus::Pending->canTransitionTo(SaleStatus::Confirmed));
        $this->assertTrue(SaleStatus::Confirmed->canTransitionTo(SaleStatus::Shipped));
        $this->assertTrue(SaleStatus::Shipped->canTransitionTo(SaleStatus::Delivered));
        $this->assertTrue(SaleStatus::Draft->canTransitionTo(SaleStatus::Cancelled));
    }

    public function test_a_delivered_sale_cannot_move_again(): void
    {
        $this->assertSame([], SaleStatus::Delivered->nextStates());
        $this->assertFalse(SaleStatus::Delivered->canTransitionTo(SaleStatus::Cancelled));
    }

    /**
     * A shipment already on the water cannot be quietly called off. Cancelling it
     * is a refund, which is a different piece of paperwork.
     */
    public function test_a_shipped_sale_cannot_be_cancelled(): void
    {
        $this->assertFalse(SaleStatus::Shipped->canTransitionTo(SaleStatus::Cancelled));
    }

    public function test_a_sale_knows_whether_it_is_still_open(): void
    {
        $open = Sale::factory()->create(['status' => SaleStatus::Shipped]);
        $done = Sale::factory()->create(['status' => SaleStatus::Delivered]);
        $gone = Sale::factory()->create(['status' => SaleStatus::Cancelled]);

        $this->assertFalse($open->is_settled);
        $this->assertTrue($done->is_settled);
        $this->assertTrue($gone->is_settled);
    }
}
