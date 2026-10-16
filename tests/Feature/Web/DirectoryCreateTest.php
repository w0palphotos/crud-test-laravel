<?php

namespace Tests\Feature\Web;

use App\Models\City;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Adding a customer and a supplier.
 *
 * The edit forms already had coverage in DirectoryEditTest. What is here is the
 * other half of the write: the create routes, the form each one renders, and the
 * one behaviour that is specific to creating — that a customer arrives with a
 * profile, because the create form is one submission writing two rows.
 */
class DirectoryCreateTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_reach_the_create_forms(): void
    {
        $this->get('/customers/create')->assertRedirect(route('login'));
        $this->get('/suppliers/create')->assertRedirect(route('login'));
        $this->post('/customers', [])->assertRedirect(route('login'));
        $this->post('/suppliers', [])->assertRedirect(route('login'));
    }

    public function test_ordinary_accounts_cannot_reach_the_create_forms(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/customers/create')->assertForbidden();
        $this->get('/suppliers/create')->assertForbidden();
        $this->post('/customers', [])->assertForbidden();
        $this->post('/suppliers', [])->assertForbidden();

        $this->assertSame(0, Customer::count());
        $this->assertSame(0, Supplier::count());
    }

    public function test_an_administrator_can_open_the_create_forms(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get('/customers/create')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Customers/Create')
                ->has('cities'));

        $this->get('/suppliers/create')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Suppliers/Create'));
    }

    public function test_a_customer_is_created_with_the_profile_that_came_with_it(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $city = City::factory()->create(['name' => 'Bandung']);

        $this->post('/customers', [
            'name' => 'Budi Santoso',
            'email' => 'budi@example.com',
            'phone' => '08123456789',
            'city_id' => $city->id,
            'address' => 'Jl. Merdeka 10',
            'tax_id' => '01.234.567.8-901.000',
            'credit_limit' => 25000000,
            'payment_terms' => 'Net 30',
        ])->assertRedirect(route('customers.index'));

        $customer = Customer::sole();

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'name' => 'Budi Santoso',
            'email' => 'budi@example.com',
            'phone' => '08123456789',
        ]);

        // The one-to-one, written in the same request. A customer with no profile
        // reads as "nobody has onboarded them yet", which would be wrong the moment
        // the form that just did collect somebody's credit limit is finished.
        $this->assertDatabaseHas('customer_profiles', [
            'customer_id' => $customer->id,
            'city_id' => $city->id,
            'address' => 'Jl. Merdeka 10',
            'tax_id' => '01.234.567.8-901.000',
            'credit_limit' => 25000000,
            'payment_terms' => 'Net 30',
        ]);
    }

    public function test_a_customer_created_without_a_limit_gets_the_column_defaults(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        // The case that used to be a 500 on the edit page and would have been one
        // here: an empty box posts '', becomes null, and two of these columns are
        // NOT NULL. Writing the column's own default is the only value that means
        // "not decided".
        $this->post('/customers', [
            'name' => 'Tanpa Profil',
            'email' => 'tanpa@example.com',
            'credit_limit' => '',
            'payment_terms' => '',
        ])->assertSessionHasNoErrors();

        $customer = Customer::sole();

        $this->assertDatabaseHas('customer_profiles', [
            'customer_id' => $customer->id,
            'credit_limit' => 0,
            'payment_terms' => 'COD',
        ]);
    }

    public function test_a_customer_email_is_normalised_before_it_is_compared(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Customer::factory()->create(['email' => 'taken@example.com']);

        $this->post('/customers', [
            'name' => 'Budi',
            'email' => 'TAKEN@example.com',
        ])->assertSessionHasErrors('email');

        $this->post('/customers', [
            'name' => 'Sari',
            'email' => '  SARI@Example.com ',
        ])->assertSessionHasNoErrors();

        // Stored folded, so the same address cannot be taken twice by two spellings.
        $this->assertDatabaseHas('customers', ['email' => 'sari@example.com']);
    }

    public function test_a_customer_needs_a_name_and_an_email(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->post('/customers', [])
            ->assertSessionHasErrors(['name', 'email']);

        $this->assertSame(0, Customer::count());
    }

    public function test_a_supplier_is_created_from_its_three_columns(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->post('/suppliers', [
            'name' => 'PT Sinar Abadi',
            'contact_email' => 'Sales@SinarAbadi.co.id',
            'phone' => '0215551234',
        ])->assertRedirect(route('suppliers.index'));

        $this->assertDatabaseHas('suppliers', [
            'name' => 'PT Sinar Abadi',
            // Folded before the write, like every other email this app stores.
            'contact_email' => 'sales@sinarabadi.co.id',
            'phone' => '0215551234',
        ]);
    }

    public function test_a_supplier_name_has_to_be_free(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Supplier::factory()->create(['name' => 'PT Sinar Abadi']);

        $this->post('/suppliers', ['name' => 'PT Sinar Abadi'])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, Supplier::count());
    }

    public function test_a_supplier_needs_only_a_name(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->post('/suppliers', [])
            ->assertSessionHasErrors('name');

        $this->post('/suppliers', ['name' => 'Tanpa Kontak'])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('suppliers', [
            'name' => 'Tanpa Kontak',
            'contact_email' => null,
            'phone' => null,
        ]);
    }
}
