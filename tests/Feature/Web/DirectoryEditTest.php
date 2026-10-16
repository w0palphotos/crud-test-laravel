<?php

namespace Tests\Feature\Web;

use App\Models\City;
use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DirectoryEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_reach_the_edit_forms(): void
    {
        $customer = Customer::factory()->create();
        $supplier = Supplier::factory()->create();

        $this->get("/customers/{$customer->id}/edit")->assertRedirect(route('login'));
        $this->patch("/customers/{$customer->id}", [])->assertRedirect(route('login'));
        $this->patch("/customers/{$customer->id}/profile", [])->assertRedirect(route('login'));
        $this->get("/suppliers/{$supplier->id}/edit")->assertRedirect(route('login'));
        $this->patch("/suppliers/{$supplier->id}", [])->assertRedirect(route('login'));
    }

    public function test_ordinary_accounts_cannot_reach_the_edit_forms(): void
    {
        $this->actingAs(User::factory()->create());
        $customer = Customer::factory()->create();
        $supplier = Supplier::factory()->create();

        $this->get("/customers/{$customer->id}/edit")->assertForbidden();
        $this->patch("/customers/{$customer->id}", [])->assertForbidden();
        $this->patch("/customers/{$customer->id}/profile", [])->assertForbidden();
        $this->get("/suppliers/{$supplier->id}/edit")->assertForbidden();
        $this->patch("/suppliers/{$supplier->id}", [])->assertForbidden();
    }

    public function test_an_administrator_can_open_the_edit_forms(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $customer = Customer::factory()->create();
        $supplier = Supplier::factory()->create();

        $this->get("/customers/{$customer->id}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Customers/Edit'));

        $this->get("/suppliers/{$supplier->id}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Suppliers/Edit'));
    }

    public function test_a_customer_can_be_updated_without_touching_its_sales(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $customer = Customer::factory()->create(['email' => 'lama@example.com']);
        $saleCount = $customer->sales()->count();

        $this->patch("/customers/{$customer->id}", [
            'name' => 'CV Baru',
            'email' => 'baru@example.com',
            'phone' => '08123456789',
        ])->assertRedirect(route('customers.index'));

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'name' => 'CV Baru',
            'email' => 'baru@example.com',
            'phone' => '08123456789',
        ]);
        $this->assertSame($saleCount, $customer->fresh()->sales()->count());
    }

    public function test_a_customer_keeps_its_own_email(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $customer = Customer::factory()->create(['email' => 'tetap@example.com']);

        $this->patch("/customers/{$customer->id}", ['email' => 'TETAP@example.com'])
            ->assertRedirect(route('customers.index'));

        $this->assertSame('tetap@example.com', $customer->fresh()->email);
    }

    public function test_a_customer_cannot_take_another_customers_email(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        Customer::factory()->create(['email' => 'dipakai@example.com']);
        $customer = Customer::factory()->create(['email' => 'bebas@example.com']);

        $this->patch("/customers/{$customer->id}", ['email' => 'dipakai@example.com'])
            ->assertSessionHasErrors('email');

        $this->assertSame('bebas@example.com', $customer->fresh()->email);
    }

    public function test_a_customer_profile_is_created_then_updated_without_duplicating(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $customer = Customer::factory()->create();
        $city = City::factory()->create();

        $this->patch("/customers/{$customer->id}/profile", [
            'city_id' => $city->id,
            'credit_limit' => 250000,
            'payment_terms' => 'Net 14',
        ])->assertRedirect(route('customers.index'));

        $this->patch("/customers/{$customer->id}/profile", [
            'city_id' => $city->id,
            'credit_limit' => 400000,
            'payment_terms' => 'Net 30',
        ])->assertRedirect(route('customers.index'));

        $this->assertSame(1, CustomerProfile::where('customer_id', $customer->id)->count());
        $this->assertDatabaseHas('customer_profiles', [
            'customer_id' => $customer->id,
            'city_id' => $city->id,
            'credit_limit' => 400000,
            'payment_terms' => 'Net 30',
        ]);
    }

    public function test_a_profile_saved_without_a_credit_limit_or_payment_terms_keeps_the_column_defaults(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $customer = Customer::factory()->create();

        // An empty text box posts '', ConvertEmptyStringsToNull turns that into
        // null, and both columns are NOT NULL with a default. Writing the default
        // is the only value that means "not decided", so a blank field must not
        // reach the insert as an explicit NULL.
        $this->patch("/customers/{$customer->id}/profile", [
            'credit_limit' => '',
            'payment_terms' => '',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('customer_profiles', [
            'customer_id' => $customer->id,
            'credit_limit' => 0,
            'payment_terms' => 'COD',
        ]);
    }

    public function test_a_profile_rejects_an_unknown_city(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $customer = Customer::factory()->create();

        $this->patch("/customers/{$customer->id}/profile", ['city_id' => 999999])
            ->assertSessionHasErrors('city_id');

        $this->assertSame(0, CustomerProfile::where('customer_id', $customer->id)->count());
    }

    public function test_a_supplier_can_be_updated_without_touching_its_products(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $supplier = Supplier::factory()->create(['name' => 'PT Lama']);
        $product = Product::factory()->create();
        $product->suppliers()->attach($supplier, [
            'unit_cost' => '100.00',
            'lead_time_days' => 7,
            'is_primary' => true,
        ]);

        $this->patch("/suppliers/{$supplier->id}", [
            'name' => 'PT Baru',
            'contact_email' => 'kontak@baru.co.id',
            'phone' => '08123456789',
        ])->assertRedirect(route('suppliers.index'));

        $this->assertDatabaseHas('suppliers', [
            'id' => $supplier->id,
            'name' => 'PT Baru',
            'contact_email' => 'kontak@baru.co.id',
        ]);
        $this->assertDatabaseHas('product_supplier', [
            'product_id' => $product->id,
            'supplier_id' => $supplier->id,
            'unit_cost' => '100.00',
            'lead_time_days' => 7,
            'is_primary' => true,
        ]);
        $this->assertSame(1, $supplier->fresh()->products()->count());
    }

    public function test_a_supplier_cannot_take_another_suppliers_name(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        Supplier::factory()->create(['name' => 'PT Dipakai']);
        $supplier = Supplier::factory()->create(['name' => 'PT Bebas']);

        $this->patch("/suppliers/{$supplier->id}", ['name' => 'PT Dipakai'])
            ->assertSessionHasErrors('name');

        $this->assertSame('PT Bebas', $supplier->fresh()->name);
    }

    public function test_directory_rows_carry_their_edit_urls(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $customer = Customer::factory()->create();
        $supplier = Supplier::factory()->create();

        $this->get('/customers')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('customers.data.0.editUrl', route('customers.edit', $customer))
            );

        $this->get('/suppliers')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('suppliers.data.0.editUrl', route('suppliers.edit', $supplier))
            );
    }
}
