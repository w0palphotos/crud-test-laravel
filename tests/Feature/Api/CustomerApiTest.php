<?php

namespace Tests\Feature\Api;

use App\Models\City;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\Salesperson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The customer endpoints.
 *
 * Two write paths, and the reason there are two is worth stating here because it is
 * the thing a client gets wrong: PATCH /api/customers/{id} touches the customer's
 * own three columns, and PATCH /api/customers/{id}/profile touches the commercial
 * terms. A client that assumed one endpoint did both would believe a phone number
 * had been saved while changing somebody's credit limit to zero.
 */
class CustomerApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Budi Santoso',
            'email' => 'budi@example.com',
        ], $overrides);
    }

    public function test_guest_cannot_reach_any_customer_endpoint(): void
    {
        $customer = Customer::factory()->create();

        $this->getJson('/api/customers')->assertUnauthorized();
        $this->getJson("/api/customers/{$customer->id}")->assertUnauthorized();
        $this->postJson('/api/customers', $this->payload())->assertUnauthorized();
        $this->patchJson("/api/customers/{$customer->id}", ['name' => 'X'])->assertUnauthorized();
        $this->patchJson("/api/customers/{$customer->id}/profile", ['credit_limit' => 1])->assertUnauthorized();
        $this->deleteJson("/api/customers/{$customer->id}")->assertUnauthorized();
    }

    public function test_it_lists_customers_with_their_profiles(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $customer = Customer::factory()->create(['name' => 'Budi']);
        $customer->profile()->create(['credit_limit' => 25000000, 'payment_terms' => 'Net 30']);

        $this->getJson('/api/customers')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Budi')
            ->assertJsonPath('data.0.profile.credit_limit', 25000000)
            ->assertJsonPath('data.0.profile.payment_terms', 'Net 30');
    }

    public function test_a_customer_without_a_profile_reports_null_rather_than_a_count(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        Customer::factory()->create();

        $this->getJson('/api/customers')
            ->assertOk()
            ->assertJsonPath('data.0.profile', null);
    }

    public function test_it_searches_by_name_or_email(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        Customer::factory()->create(['name' => 'Budi Santoso']);
        Customer::factory()->create(['name' => 'Sari Wijaya', 'email' => 'sari@example.com']);

        $this->getJson('/api/customers?q=budi')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/customers?q=sari@example')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/customers?q=nobody')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_it_shows_a_single_customer_with_its_city(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $city = City::factory()->create(['name' => 'Bandung']);
        $customer = Customer::factory()->create(['name' => 'Budi']);
        $customer->profile()->create(['city_id' => $city->id]);

        $this->getJson("/api/customers/{$customer->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Budi')
            ->assertJsonPath('data.profile.city.name', 'Bandung');
    }

    public function test_it_creates_a_customer_and_its_profile_together(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/customers', $this->payload([
            'phone' => '08123456789',
            'credit_limit' => 25000000,
            'payment_terms' => 'Net 30',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.email', 'budi@example.com')
            ->assertJsonPath('data.profile.credit_limit', 25000000)
            ->assertJsonPath('data.profile.payment_terms', 'Net 30');

        $customer = Customer::sole();

        $this->assertDatabaseHas('customer_profiles', [
            'customer_id' => $customer->id,
            'credit_limit' => 25000000,
            'payment_terms' => 'Net 30',
        ]);
    }

    public function test_it_creates_a_customer_whose_blank_limit_takes_the_column_default(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        // Not a zero because nobody decided, and emphatically not a null: both
        // columns are NOT NULL, and a null is an integrity violation rather than
        // an unanswered question.
        $this->postJson('/api/customers', $this->payload([
            'credit_limit' => null,
            'payment_terms' => null,
        ]))->assertCreated()
            ->assertJsonPath('data.profile.credit_limit', 0)
            ->assertJsonPath('data.profile.payment_terms', 'COD');
    }

    public function test_it_refuses_a_duplicate_email(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        Customer::factory()->create(['email' => 'budi@example.com']);

        $this->postJson('/api/customers', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_it_updates_the_customer_without_touching_the_profile(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $customer = Customer::factory()->create(['name' => 'Budi']);
        $customer->profile()->create(['credit_limit' => 25000000, 'payment_terms' => 'Net 30']);

        $this->patchJson("/api/customers/{$customer->id}", ['phone' => '0812000000'])
            ->assertOk()
            ->assertJsonPath('data.phone', '0812000000')
            // Untouched, and said so — not silently reset to the defaults.
            ->assertJsonPath('data.profile.credit_limit', 25000000);
    }

    public function test_it_creates_a_profile_later_for_a_customer_that_had_none(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $customer = Customer::factory()->create();

        $this->patchJson("/api/customers/{$customer->id}/profile", [
            'credit_limit' => 40000000,
            'payment_terms' => 'Net 30',
        ])
            ->assertOk()
            ->assertJsonPath('data.profile.credit_limit', 40000000);

        $this->assertSame(1, $customer->profile()->count());
    }

    public function test_it_deletes_a_customer_that_has_no_sales(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $customer = Customer::factory()->create();

        $this->deleteJson("/api/customers/{$customer->id}")->assertNoContent();

        $this->assertSame(0, Customer::count());
    }

    public function test_it_refuses_to_delete_a_customer_who_still_has_sales(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $customer = Customer::factory()->create();
        $customer->sales()->create(Sale::factory()->raw([
            'customer_id' => $customer->id,
            'salesperson_id' => Salesperson::factory()->create()->id,
            'city_id' => City::factory()->create()->id,
        ]));

        // sales.customer_id restricts, so letting the delete through would be a
        // foreign key violation: a 500 with no sentence in it. The refusal says
        // what is in the way instead.
        $this->deleteJson("/api/customers/{$customer->id}")->assertStatus(422);

        $this->assertSame(1, Customer::count());
    }
}
