<?php

namespace Tests\Feature\Web;

use App\Models\City;
use App\Models\Sale;
use App\Models\Salesperson;
use App\Models\Tag;
use App\Models\User;
use App\Support\ReferenceRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The reference lists the desk adds to itself.
 *
 * One suite for cities, salespeople and tags rather than three, because they are
 * one feature: one controller driven by one registry, and a defect in the shared
 * path — the write, the unique index, the delete guard — would show up in all
 * three and be worth asserting once each. What is particular to a table is
 * asserted against that table.
 */
class ReferenceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every verb the six routes answer, as a path with the ids already in it.
     *
     * Authorisation is the middleware's, and the middleware is shared by every
     * route in the admin group. Asserting it once per route is how a route added
     * later without the guard is caught.
     *
     * @return list<array{0: string, 1: string}> method, path template
     */
    public static function routes(): array
    {
        return [
            'list' => ['get', '/references/cities'],
            'add form' => ['get', '/references/cities/create'],
            'add' => ['post', '/references/cities'],
            'change form' => ['get', '/references/cities/1/edit'],
            'change' => ['patch', '/references/cities/1'],
            'delete' => ['delete', '/references/cities/1'],
        ];
    }

    /**
     * The five of the six that are not the list itself.
     *
     * The list is readable by anyone signed in; these are not. Split out because a
     * provider that mixed the two could only be asserted one way at a time, and
     * "a reader may look and may not touch" is a claim about all five of these.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function writes(): array
    {
        return array_values(array_filter(
            self::routes(),
            fn (array $route): bool => $route[0] !== 'get' || str_contains($route[1], '/1'),
        ));
    }

    public function test_guests_cannot_reach_the_reference_lists(): void
    {
        foreach (self::routes() as [$method, $path]) {
            $this->{$method}($path)->assertRedirect(route('login'));
        }
    }

    /**
     * Reading a list is open to every signed-in account; writing one is not.
     *
     * The boundary is asserted per verb rather than per page, because "a reader may
     * look and may not touch" is a claim about all six routes and a page load would
     * say nothing about the PATCH.
     */
    #[DataProvider('writes')]
    public function test_ordinary_accounts_can_read_a_list_but_not_write_one(string $method, string $path): void
    {
        $this->actingAs(User::factory()->create());

        $this->{$method}($path)->assertForbidden();
    }

    public function test_an_ordinary_account_may_open_a_reference_list(): void
    {
        $this->actingAs(User::factory()->create());

        City::factory()->create(['name' => 'Bandung']);

        $this->get('/references/cities')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Reference/Index')
                // So the page can drop its Tambah, Ubah and Hapus rather than
                // offering a reader three buttons that each fail.
                ->where('can.write', false)
                ->where('rows.data.0.name', 'Bandung')
            );
    }

    public function test_an_administrator_can_open_a_reference_list(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        City::factory()->create(['name' => 'Bandung', 'province' => 'Jawa Barat']);

        $this->get('/references/cities')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Reference/Index')
                ->where('reference.label', 'Kota')
                ->where('reference.key', 'cities')
                ->has('reference.columns', 2)
                ->has('rows.data', 1)
                ->where('rows.data.0.name', 'Bandung')
                ->where('rows.data.0.editUrl', route('references.edit', ['cities', 1]))
                ->where('rows.data.0.destroyUrl', route('references.destroy', ['cities', 1]))
            );
    }

    public function test_the_add_and_change_forms_describe_the_list_they_are_for(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $city = City::factory()->create(['name' => 'Surabaya']);

        $this->get('/references/salespeople/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Reference/Form')
                ->where('row', null)
                // commission_rate and colour are NOT NULL with a default, so the
                // add form has to arrive holding those defaults rather than empty.
                ->where('values.commission_rate', 0)
                ->where('storeUrl', route('references.store', 'salespeople'))
                ->where('cancelUrl', route('references.index', 'salespeople'))
                ->has('reference.fields', 4)
            );

        $this->get("/references/cities/{$city->id}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Reference/Form')
                ->where('row.name', 'Surabaya')
                ->where('values.name', 'Surabaya')
                ->where('updateUrl', route('references.update', ['cities', $city->id]))
            );
    }

    public function test_a_city_can_be_added(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->from('/references/cities/create')
            ->post('/references/cities', ['name' => 'Medan', 'province' => 'Sumatera Utara'])
            ->assertRedirect(route('references.index', 'cities'))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('cities', ['name' => 'Medan', 'province' => 'Sumatera Utara']);
    }

    public function test_a_salesperson_can_be_added(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->post('/references/salespeople', [
            'name' => 'Rina',
            'email' => 'rina@example.com',
            'phone' => '0812000000',
            'commission_rate' => '3.5',
        ])->assertRedirect(route('references.index', 'salespeople'));

        $this->assertDatabaseHas('salespeople', [
            'name' => 'Rina',
            'email' => 'rina@example.com',
            'phone' => '0812000000',
        ]);

        $this->assertEquals(3.5, (float) Salesperson::query()->where('email', 'rina@example.com')->value('commission_rate'));
    }

    public function test_a_tag_can_be_added(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->post('/references/tags', ['name' => 'Urgent', 'colour' => '#dc2626'])
            ->assertRedirect(route('references.index', 'tags'));

        $this->assertDatabaseHas('tags', ['name' => 'Urgent', 'colour' => '#dc2626']);
    }

    public function test_a_name_is_required(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->post('/references/cities', ['name' => '', 'province' => 'Jawa Barat'])
            ->assertSessionHasErrors('name');

        $this->assertDatabaseCount('cities', 0);
    }

    public function test_a_colour_that_is_not_a_hex_value_is_refused(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->post('/references/tags', ['name' => 'Urgent', 'colour' => 'merah'])
            ->assertSessionHasErrors('colour');

        $this->assertDatabaseCount('tags', 0);
    }

    public function test_a_name_already_on_file_is_refused(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        City::factory()->create(['name' => 'Medan']);

        $this->post('/references/cities', ['name' => 'Medan'])->assertSessionHasErrors('name');

        $this->assertDatabaseCount('cities', 1);
    }

    public function test_an_email_already_on_file_is_refused(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Salesperson::factory()->create(['email' => 'rina@example.com']);

        $this->post('/references/salespeople', [
            'name' => 'Someone Else',
            'email' => 'rina@example.com',
        ])->assertSessionHasErrors('email');

        $this->assertDatabaseCount('salespeople', 1);
    }

    /**
     * Saving a row under the name it already has must not be reported as a clash
     * with itself — the failure a unique column produces when the rule forgets to
     * ignore the row being edited.
     */
    public function test_changing_a_row_does_not_clash_with_itself(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $city = City::factory()->create(['name' => 'Medan', 'province' => null]);

        $this->patch("/references/cities/{$city->id}", ['name' => 'Medan', 'province' => 'Sumatera Utara'])
            ->assertRedirect(route('references.index', 'cities'))
            ->assertSessionHasNoErrors();

        $this->assertSame('Sumatera Utara', $city->fresh()->province);
    }

    public function test_a_name_belonging_to_another_row_is_still_refused(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        City::factory()->create(['name' => 'Medan']);
        $other = City::factory()->create(['name' => 'Padang']);

        $this->patch("/references/cities/{$other->id}", ['name' => 'Medan'])
            ->assertSessionHasErrors('name');

        $this->assertSame('Padang', $other->fresh()->name);
    }

    public function test_an_address_is_folded_to_the_spelling_the_index_compares(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->post('/references/salespeople', [
            'name' => 'Rina',
            'email' => '  Rina@Example.COM ',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('salespeople', ['email' => 'rina@example.com']);
    }

    /**
     * An empty optional field has to become something the column can hold.
     *
     * Two ways it goes wrong, both silent. An untouched text field posts an empty
     * string, which `nullable` accepts, so "no province" is stored as a province
     * that happens to be the empty string. And a NOT NULL column with a default —
     * a commission rate, a tag's colour — cannot be given null at all, so the
     * write fails outright on a form a person simply left alone.
     */
    public function test_an_untouched_optional_column_stores_the_columns_own_default(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->post('/references/cities', ['name' => 'Medan', 'province' => ''])->assertSessionHasNoErrors();
        $this->assertNull(City::query()->where('name', 'Medan')->value('province'));

        $this->post('/references/salespeople', ['name' => 'Rina', 'commission_rate' => ''])->assertSessionHasNoErrors();
        $this->assertEquals(
            0,
            (float) Salesperson::query()->where('name', 'Rina')->value('commission_rate'),
        );

        $this->post('/references/tags', ['name' => 'Urgent', 'colour' => ''])->assertSessionHasNoErrors();
        $this->assertSame('#e6e8eb', Tag::query()->where('name', 'Urgent')->value('colour'));
    }

    /**
     * The delete guard, for the rows `sales` holds with restrictOnDelete.
     *
     * Without the guard this is a foreign key violation rather than a sentence,
     * which on production is a 500 with nothing in it to act on.
     */
    public function test_a_row_a_sale_still_points_at_cannot_be_deleted(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $city = City::factory()->create(['name' => 'Medan']);
        $salesperson = Salesperson::factory()->create(['name' => 'Rina']);

        Sale::factory()->create(['city_id' => $city->id, 'salesperson_id' => $salesperson->id]);

        $this->from(route('references.index', 'cities'))
            ->delete("/references/cities/{$city->id}")
            ->assertSessionHasErrors("references.{$city->id}");

        $this->assertDatabaseHas('cities', ['name' => 'Medan']);

        $this->from(route('references.index', 'salespeople'))
            ->delete("/references/salespeople/{$salesperson->id}")
            ->assertSessionHasErrors("references.{$salesperson->id}");

        $this->assertDatabaseHas('salespeople', ['name' => 'Rina']);
    }

    public function test_a_row_nothing_refers_to_can_be_deleted(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $city = City::factory()->create(['name' => 'Medan']);

        $this->delete("/references/cities/{$city->id}")
            ->assertRedirect(route('references.index', 'cities'))
            ->assertSessionHas('status')
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('cities', ['name' => 'Medan']);
    }

    /**
     * `{reference}` arrives from the browser, so the registry is the boundary.
     *
     * A key naming any other table — a table that exists, a table that does not —
     * must answer 404 rather than becoming a query target. `users` is the sharp one
     * to try: it is a real table with an email and a password column, and a path
     * that resolved it would hand the desk a way to write accounts.
     */
    public function test_a_key_outside_the_registry_is_not_a_table(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $city = City::factory()->create(['name' => 'Medan']);

        $this->get('/references/users')->assertNotFound();
        $this->get('/references/sales')->assertNotFound();
        $this->get('/references/cities/create/../users')->assertNotFound();

        $this->post('/references/users', ['name' => 'Backdoor', 'email' => 'backdoor@example.com'])
            ->assertNotFound();

        // One account, the administrator who made the request.
        $this->assertDatabaseCount('users', 1);

        $this->patch("/references/users/{$city->id}", ['name' => 'Medan'])->assertNotFound();
        $this->delete("/references/cities/{$city->id}/../sales")->assertNotFound();

        $this->assertDatabaseHas('cities', ['name' => 'Medan']);
    }

    /**
     * Case folding in the search box.
     *
     * `whereLike` rather than a plain LIKE: SQLite folds ASCII case and Postgres
     * does not, so a search that finds the row on the test database finds nothing
     * on the engine production runs. Asserting a lowercase query against a
     * capitalised row is what catches the regression.
     */
    public function test_the_search_finds_a_row_whatever_its_case(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        City::factory()->create(['name' => 'Bandung']);
        City::factory()->create(['name' => 'Medan']);

        $this->get('/references/cities?q=band')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('search', 'band')
                ->has('rows.data', 1)
                ->where('rows.data.0.name', 'Bandung')
            );
    }

    public function test_a_row_that_is_not_there_is_a_404_rather_than_an_error(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get('/references/cities/999/edit')->assertNotFound();
        $this->patch('/references/cities/999', ['name' => 'Medan'])->assertNotFound();
        $this->delete('/references/cities/999')->assertNotFound();
    }

    /**
     * The rail is drawn from the registry, so a list declared in PHP and nowhere
     * else is reachable. Without this the navigation could lose an entry and every
     * assertion above would still pass.
     */
    public function test_every_reference_list_is_shared_for_the_navigation(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get('/products')
            ->assertOk()
            ->assertInertia(function ($page): void {
                $routes = $page->toArray()['props']['routes'];
                $nav = $page->toArray()['props']['referenceNav'];

                foreach (ReferenceRegistry::keys() as $key) {
                    $this->assertSame(
                        route('references.index', $key),
                        $routes["references.{$key}"],
                        "routes.references.{$key} does not point at the list it names.",
                    );

                    $this->assertContains($key, array_column($nav, 'key'));
                }
            });
    }
}
