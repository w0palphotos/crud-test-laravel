<?php

namespace Tests\Feature\Api;

use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The supplier endpoints.
 *
 * The delete test is the one worth reading. Deleting a supplier who supplies
 * anything would succeed at the database and quietly strip the agreed price and
 * lead time off every product they were backing, so it is refused instead.
 */
class SupplierApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'PT Sinar Abadi',
        ], $overrides);
    }

    public function test_guest_cannot_reach_any_supplier_endpoint(): void
    {
        $supplier = Supplier::factory()->create();

        $this->getJson('/api/suppliers')->assertUnauthorized();
        $this->getJson("/api/suppliers/{$supplier->id}")->assertUnauthorized();
        $this->postJson('/api/suppliers', $this->payload())->assertUnauthorized();
        $this->patchJson("/api/suppliers/{$supplier->id}", ['name' => 'X'])->assertUnauthorized();
        $this->deleteJson("/api/suppliers/{$supplier->id}")->assertUnauthorized();
    }

    public function test_it_lists_suppliers_with_their_product_counts(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $supplier = Supplier::factory()->create(['name' => 'PT Sinar Abadi']);
        $product = Product::factory()->create();

        $product->suppliers()->attach($supplier, ['unit_cost' => '100.00']);

        $this->getJson('/api/suppliers')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'PT Sinar Abadi')
            ->assertJsonPath('data.0.products_count', 1);
    }

    public function test_it_omits_a_product_count_it_never_counted(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $supplier = Supplier::factory()->create();

        // show() does not run a withCount, so it has no count to report. A key
        // reading zero here would be a lie about a supplier who supplies half the
        // catalogue — absence says "not asked", which is true.
        $this->getJson("/api/suppliers/{$supplier->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.products_count');
    }

    public function test_it_searches_by_name_or_contact_email(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        Supplier::factory()->create(['name' => 'PT Sinar Abadi']);
        Supplier::factory()->create(['name' => 'PT-other', 'contact_email' => 'beli@lain.co.id']);

        $this->getJson('/api/suppliers?q=sinar')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/suppliers?q=lain.co.id')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_it_creates_a_supplier(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/suppliers', $this->payload([
            'contact_email' => 'Sales@SinarAbadi.co.id',
            'phone' => '0215551234',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.name', 'PT Sinar Abadi')
            // Folded on the way in, like every other email this app stores.
            ->assertJsonPath('data.contact_email', 'sales@sinarabadi.co.id');
    }

    public function test_a_supplier_only_needs_a_name(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/suppliers', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.contact_email', null)
            ->assertJsonPath('data.phone', null);
    }

    public function test_it_refuses_a_duplicate_name(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        Supplier::factory()->create(['name' => 'PT Sinar Abadi']);

        $this->postJson('/api/suppliers', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_it_updates_a_supplier_without_touching_its_product_pairings(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $supplier = Supplier::factory()->create(['name' => 'PT Lama']);
        $product = Product::factory()->create();
        $product->suppliers()->attach($supplier, ['unit_cost' => '100.00', 'lead_time_days' => 7]);

        $this->patchJson("/api/suppliers/{$supplier->id}", ['name' => 'PT Baru'])
            ->assertOk()
            ->assertJsonPath('data.name', 'PT Baru');

        // Amending contact details must not rewrite purchasing history.
        $this->assertDatabaseHas('product_supplier', [
            'product_id' => $product->id,
            'supplier_id' => $supplier->id,
            'unit_cost' => '100.00',
            'lead_time_days' => 7,
        ]);
    }

    public function test_it_deletes_a_supplier_that_supplies_nothing(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $supplier = Supplier::factory()->create();

        $this->deleteJson("/api/suppliers/{$supplier->id}")->assertNoContent();

        $this->assertSame(0, Supplier::count());
    }

    public function test_it_refuses_to_delete_a_supplier_who_still_supplies_products(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $supplier = Supplier::factory()->create();
        Product::factory()->create()->suppliers()->attach($supplier, ['unit_cost' => '100.00']);

        // product_supplier cascades, so the delete would report success while
        // quietly taking the price off every product this supplier was backing.
        $this->deleteJson("/api/suppliers/{$supplier->id}")->assertStatus(422);

        $this->assertSame(1, Supplier::count());
    }
}
