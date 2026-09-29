# 07. Testing

## The current state

```sh
php artisan test
```

```text
45 tests, 174 assertions, all passing
```

Broken down:

| File | Tests | Covers |
| --- | --- | --- |
| `tests/Feature/Api/ProductApiTest.php` | 16 | Product CRUD, pagination, validation |
| `tests/Feature/Api/AccountApiTest.php` | 19 | Registration, self-service updates, token revocation |
| `tests/Feature/Api/TokenApiTest.php` | 8 | Issuing, using, and revoking tokens |
| `tests/Feature/ExampleTest.php` | 1 | That `GET /` returns 200 |
| `tests/Unit/ExampleTest.php` | 1 | Placeholder from the skeleton |

## Running part of the suite

```sh
php artisan test                                   # everything
php artisan test --compact                         # terse output
php artisan test tests/Feature/Api                 # one directory
php artisan test tests/Feature/Api/AccountApiTest.php
php artisan test --filter=test_it_creates_a_product # one test by name
php artisan test --filter=stray_current_password   # substring match
```

`--filter` is the one you will use most. Test method names in this project are long and
descriptive on purpose, so a substring is usually enough.

## What the tests do not touch

`phpunit.xml` overrides the environment for the suite:

```xml
<env name="APP_ENV" value="testing"/>
<env name="BCRYPT_ROUNDS" value="4"/>
<env name="CACHE_STORE" value="array"/>
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
<env name="MAIL_MAILER" value="array"/>
<env name="QUEUE_CONNECTION" value="sync"/>
<env name="SESSION_DRIVER" value="array"/>
```

The two that matter most:

- `:memory:` means each test run gets a throwaway in-memory database. Your real
  `database/database.sqlite` is never modified by the suite.
- `BCRYPT_ROUNDS=4` instead of the default 12. Password hashing is deliberately slow, and
  at 12 rounds a suite with many password operations gets slow enough that people stop
  running it. Four rounds is fast and is fine for a test.

## The two things every API test needs

### `RefreshDatabase` for a clean database

```php
use Illuminate\Foundation\Testing\RefreshDatabase;

class ProductApiTest extends TestCase
{
    use RefreshDatabase;
}
```

The trait runs your migrations once and wraps each test in a transaction that is rolled
back at the end. Each test starts empty, which is what makes them independent and
order-independent.

### `Sanctum::actingAs` to be authenticated

```php
use Laravel\Sanctum\Sanctum;

Sanctum::actingAs(User::factory()->create());
```

That makes the next request in the test authenticated as that user, without needing a real
token.

Use it when the test is about business logic. Use a real token when the test is about
tokens. Both patterns appear in `AccountApiTest`:

```php
// business logic: actingAs is fine
Sanctum::actingAs(User::factory()->create(['password' => 'original-password']));

// token behaviour: needs a real one, and the actingAs user needs a token to own
$user = User::factory()->create();
$token = $user->createToken('swagger-ui')->plainTextToken;

$this->withToken($token)->patchJson('/api/account', [...])->assertOk();
```

## Writing a test

Start with the generator:

```sh
php artisan make:test --phpunit Api/OrderApiTest
```

That creates `tests/Feature/Api/OrderApiTest.php`. Do not put `Feature/` or `Api/` in a
name you pass to `make:test`, it appends the path itself.

A full example showing the house style:

```php
<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Valid payload shared by the create/update tests.
     *
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Mechanical Keyboard',
            'description' => 'Hot-swappable 75% board.',
            'price' => 129.99,
            'stock' => 42,
        ], $overrides);
    }

    public function test_it_creates_a_product(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/products', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Mechanical Keyboard')
            ->assertJsonPath('data.price', 129.99);

        $this->assertDatabaseHas('products', [
            'name' => 'Mechanical Keyboard',
            'price' => 129.99,
        ]);
    }
}
```

Conventions in this project:

- Test names read as sentences: `test_it_creates_a_product`, `test_it_rejects_a_duplicate_email`
- `postJson` / `patchJson` / `getJson` / `deleteJson`, never the plain versions. The `Json`
  variants set the `Accept` header, which is what makes error responses come back as JSON
- assert on both the response and the database when a write happens. A 200 with nothing
  written is a real failure mode
- a private helper builds the valid payload so the tests that vary one field stay short

## Useful assertions

| Assertion | Checks |
| --- | --- |
| `assertOk()` / `assertCreated()` / `assertNoContent()` | status code |
| `assertUnauthorized()` / `assertNotFound()` / `assertUnprocessable()` | the 4xx you expected |
| `assertJsonPath('data.name', 'X')` | one exact value in the body |
| `assertJsonCount(3, 'data')` | array length |
| `assertJsonStructure([...])` | keys exist, at any depth |
| `assertJsonValidationErrors(['price'])` | 422 with errors on those keys |
| `assertJsonMissing([...])` | a value is absent, e.g. another user's email |
| `assertDatabaseHas('products', [...])` | a row exists |
| `assertDatabaseMissing('products', [...])` | no row exists |
| `assertDatabaseCount('products', 0)` | row count |
| `->assertHeader('X-Foo', 'bar')` | response header |

## Tests that caught real bugs

Worth reading, because each one is a bug that was live in the code at some point.

### The `#[Fillable]` trap

`test_it_creates_a_product` asserts `assertJsonPath('data.price', 129.99)`. If someone adds
a column to the migration and forgets `#[Fillable]`, the value is dropped silently and this
assertion fails. That is the test doing its job as a regression guard for a silent failure.

### The guard cache trap

```php
public function test_a_deleted_account_token_no_longer_authenticates(): void
{
    $user = User::factory()->create();
    $token = $user->createToken('swagger-ui')->plainTextToken;

    $this->withToken($token)->deleteJson('/api/account')->assertNoContent();

    // The application instance is shared across calls in a test, so the guard
    // would otherwise reuse the user it already resolved and never re-check
    // the token. Forgetting guards forces a real re-authentication.
    $this->app->make('auth')->forgetGuards();

    $this->withToken($token)->getJson('/api/account')->assertUnauthorized();
}
```

Without the `forgetGuards()` line this test returns 200 and fails, even though the token is
genuinely dead. The cause is that a test shares one application instance, and
`AuthManager` caches resolved guards in `protected $guards = []`. Add that line whenever the
point of the test is that authentication changed.

### Proving a fix is real

When the `current_password` rule was fixed, the new tests were checked against the old code
by reverting the one line and re-running. Both failed with
`422 "The password is incorrect"`, which is what proved the tests were not vacuous:

```sh
# temporarily revert, confirm failure, restore
php artisan test --filter=stray_current_password
```

A test that passes both before and after a fix is not testing the fix.

## Tests are not the whole story

Two real bugs in this project were invisible to the suite:

1. An unauthenticated browser request returned **500** instead of 401, because
   `getJson()` sends `Accept: application/json` and that skipped the redirect path that was
   throwing. Found with a plain `curl` that sent no `Accept` header.
2. Swagger UI pre-filled a request body with mismatched `password` and
   `password_confirmation`, so Execute failed on the first click. Found by looking at the
   browser, not by any assertion.

So run the suite, and also click through the thing. They catch different classes of problem.

## Before you commit

```sh
php artisan test
vendor/bin/pint --dirty --format agent
```

`vendor/bin/pint --test` checks without writing, which is what you want in CI.
