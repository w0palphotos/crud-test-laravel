# 04. Adding a field

This is the task you will do most often, so it is worth doing once carefully. We will add a
`sku` column (stock keeping unit) to `Product`.

**Nothing in this document has been applied to the project.** It is a walkthrough. Run the
commands yourself and the code samples are what you should end up with.

## The rule to remember

A new field is not one line. It is **five places**, and the first one you forget is the
quiet one that wastes the most time.

```
1. migration      the column exists
2. #[Fillable]    Eloquent will accept it from a request
3. request rules  the API validates it
4. Resource       it appears in JSON and in Swagger UI
5. a test         you will notice when step 2 is missing
```

Skip step 2 and the field is dropped **silently**. No error, no exception, the value just
never arrives. That is the failure mode to watch for.

## Step 1: the migration

```sh
php artisan make:migration add_sku_to_products_table --table=products
```

That creates `database/migrations/<timestamp>_add_sku_to_products_table.php`. Fill in both
methods. `up()` applies it, `down()` undoes it, and `migrate:rollback` calls `down()`.

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('sku')->nullable()->unique()->after('id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['sku']);
            $table->dropColumn('sku');
        });
    }
};
```

Notes on the column definition:

- `nullable()` so existing rows do not need a value. SQLite cannot add a `NOT NULL` column
  to a table that already has rows without a default.
- `unique()` adds a database-level guarantee. The `unique` validation rule in step 3 is
  about the error message a user sees, the database constraint is about correctness. You
  want both.
- `after('id')` only affects MySQL and MariaDB. SQLite ignores it. Keeping it makes the
  column order match your reading of the model.

Run it:

```sh
php artisan migrate
```

Check it landed:

```sh
php artisan tinker
>>> Schema::getColumnListing('products')
```

## Step 2: add it to `#[Fillable]`

```php
// app/Models/Product.php
#[Fillable(['sku', 'name', 'description', 'price', 'stock'])]
class Product extends Model
```

Miss this and `Product::create($request->validated())` quietly drops `sku`. No exception.
This is the step people forget.

## Step 3: validation rules

Create the request for create, and edit the one for update.

```php
// app/Http/Requests/StoreProductRequest.php
public function rules(): array
{
    return [
        'sku' => ['nullable', 'string', 'max:64', Rule::unique('products', 'sku')],
        'name' => ['required', 'string', 'max:255'],
        'description' => ['nullable', 'string', 'max:1000'],
        'price' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
        'stock' => ['required', 'integer', 'min:0'],
    ];
}
```

```php
// app/Http/Requests/UpdateProductRequest.php
public function rules(): array
{
    return [
        'sku' => ['sometimes', 'nullable', 'string', 'max:64', Rule::unique('products', 'sku')->ignore($this->route('product'))],
        'name' => ['sometimes', 'required', 'string', 'max:255'],
        // ... the rest unchanged
    ];
}
```

The difference between the two files is `sometimes`:

| Rule | Meaning |
| --- | --- |
| `required` | must be present and non-empty |
| `nullable` | may be present as `null` |
| `sometimes` | only validate this key if the request contains it. This is what makes `PATCH` partial |
| `decimal:0,2` | between 0 and 2 decimal places, so `9.5` passes and `9.555` fails |
| `Rule::unique(...)` | rejects a value already in that column |
| `->ignore($id)` | on an update, excludes the record's own current value, otherwise saving a product without changing its sku would fail |

`Rule::unique` needs the import. Pint sorts imports for you, so run it after editing:

```sh
vendor/bin/pint --dirty --format agent
```

## Step 4: the Resource and the OpenAPI schema

In this project these are the same file, which is the main thing to understand about
`ProductResource`. One `#[OA\Schema]` block describes the shape for both request bodies
and responses.

```php
// app/Http/Resources/ProductResource.php
#[OA\Schema(
    schema: 'Product',
    required: ['id', 'sku', 'name', 'price', 'stock', 'created_at', 'updated_at'],
    properties: [
        'id' => new OA\Property(property: 'id', type: 'integer', readOnly: true, example: 1),
        'sku' => new OA\Property(property: 'sku', type: 'string', maxLength: 64, nullable: true, example: 'KB-75-HS-001'),
        'name' => new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'Mechanical Keyboard'),
        // ... unchanged
    ],
)]
class ProductResource extends JsonResource
```

Three things to know about this block:

- `readOnly: true` tells Swagger UI to hide the field from request bodies while still
  showing it in responses. `id` and the timestamps are marked this way, which is why the
  create form in Swagger UI does not ask you for an id.
- A field is only **required** in the UI if it is in the `required` array. I put `sku` in
  there even though the rule is `nullable`, which is a small inconsistency. Either drop it
  from `required`, or make the rule `required`. Pick one and make them agree.
- Give every field an `example`. Swagger UI pre-fills the request body from it, and a body
  with mismatched or missing examples fails on the first click of Execute. That is a real
  papercut in this project, see [06](06-auth-and-tokens.md).

If the field needs different validation on create and update, a single shared schema gets
awkward. At that point declare a second schema for the write payload. Do not contort the
one schema to fake it.

## Step 5: the factory

```php
// database/factories/ProductFactory.php
public function definition(): array
{
    return [
        'sku' => fake()->unique()->bothify('KB-???-####'),
        'name' => fake()->unique()->words(3, true),
        'description' => fake()->optional()->sentence(),
        'price' => fake()->randomFloat(2, 5, 500),
        'stock' => fake()->numberBetween(0, 250),
    ];
}
```

The factory feeds both the seeder and every test. Leaving `sku` out would make
`Product::factory()->create()` insert a null, and then your `unique` test has nothing to
work with.

## Step 6: tests

```php
// tests/Feature/Api/ProductApiTest.php
public function test_it_creates_a_product_with_a_sku(): void
{
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/products', [
        'sku' => 'KB-75-HS-001',
        'name' => 'Mechanical Keyboard',
        'price' => 129.99,
        'stock' => 42,
    ])
        ->assertCreated()
        ->assertJsonPath('data.sku', 'KB-75-HS-001');

    $this->assertDatabaseHas('products', ['sku' => 'KB-75-HS-001']);
}

public function test_it_rejects_a_duplicate_sku(): void
{
    Sanctum::actingAs(User::factory()->create());
    Product::factory()->create(['sku' => 'KB-75-HS-001']);

    $this->postJson('/api/products', [
        'sku' => 'KB-75-HS-001',
        'name' => 'Another Board',
        'price' => 99.00,
        'stock' => 5,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('sku');
}
```

The first test is also your step 2 detector. If you forgot `#[Fillable]`, the response will
have no `sku` and the test fails. That is the point of writing it.

## Step 7: regenerate the spec and check formatting

```sh
php artisan l5-swagger:generate
php artisan test
vendor/bin/pint --dirty --format agent
```

The spec file is gitignored, so `generate` is not optional. Skip it and the browser keeps
showing the old schema with no `sku`.

## Step 8: confirm it in the browser

```sh
php artisan serve
```

Open `http://localhost:8000/api/documentation`, reload, and expand
`POST /api/products`. `sku` should appear in the body with the value `KB-75-HS-001`.

## Reseeding

If you added a non-nullable column, existing seeded rows will not have a value:

```sh
php artisan migrate:fresh --seed
```

This drops every table, re-runs all migrations, and re-runs the seeder. It also deletes
the `test@example.com` user and re-creates it with the same password, so your Swagger UI
login keeps working. It does clear any tokens you have issued, so you will need a new one
from `POST /api/auth/token`.

## Checklist before you commit

```sh
php artisan test
vendor/bin/pint --test
php artisan l5-swagger:generate
php artisan route:list --path=api --except-vendor
```

If you added a route, regenerate is not enough on its own, the route has to exist too. And
if you changed anything under `#[OA\...]`, remember the spec is not committed, so whoever
clones the repo has to run `generate` themselves. Say so in your commit message.

## Removing a field later

The reverse, in this order, so the app is never in a state where the code expects a column
that is gone:

1. delete the tests
2. remove the `OA\Property` and the `required` entry
3. remove the rules from both request classes
4. remove it from `#[Fillable]` and the factory
5. `php artisan migrate` with a `dropColumn` migration

Reversing that order breaks the app at each step, which is why it is worth following.
