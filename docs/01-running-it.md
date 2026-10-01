# 01. Running the project

## One time setup

```sh
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan l5-swagger:generate
php artisan serve
```

What each line does:

| Command | Why |
| --- | --- |
| `composer install` | Installs packages listed in `composer.lock` |
| `cp .env.example .env` | Creates your local config file. `.env` is gitignored, yours stays private |
| `php artisan key:generate` | Generates the `APP_KEY` used to encrypt cookies and tokens. Without it the app throws errors |
| `touch database/database.sqlite` | Creates the empty SQLite file. `config/database.php` defaults to `DB_CONNECTION=sqlite` |
| `php artisan migrate` | Runs the migrations, which creates the tables |
| `php artisan migrate --seed` | Also runs `database/seeders/DatabaseSeeder.php`, which inserts 10 products and one user |
| `php artisan l5-swagger:generate` | Scans `app/` for `#[OA\...]` attributes and writes the spec |

## The credentials you seed

`DatabaseSeeder` creates one user. The email is hardcoded; the password comes from
`UserFactory`, where it is set to `password`:

```php
// database/seeders/DatabaseSeeder.php
Product::factory(10)->create();

User::factory()->create([
    'name' => 'Test User',
    'email' => 'test@example.com',
]);
```

```php
// database/factories/UserFactory.php
'password' => static::$password ??= Hash::make('password'),
```

So the login is `test@example.com` / `password`.

## Open Swagger UI

```text
http://localhost:8000/api/documentation
```

## The two steps in Swagger UI

1. Expand `POST /api/auth/token`, click **Try it out**, click **Execute**, and copy the
   `token` value from the response.
2. Click **Authorize** at the top right, paste the token, click **Authorize**.

Every other endpoint needs step 2 first.

A Sanctum token looks like this:

```text
3|Ip7Xq7KgAf3nRt8s...
```

Paste the whole thing, including the `3|` prefix. That is one opaque string, and the part
before the pipe is part of it. Dropping the prefix gives you a 401 that looks like bad
credentials.

## Commands you will use often

```sh
php artisan serve                          # start the dev server on :8000
php artisan test                           # run the whole test suite
php artisan test --filter=test_it_lists    # run tests matching a name
php artisan test tests/Feature/Api         # run one directory
php artisan route:list --path=api          # see every API route
php artisan route:list --path=api --except-vendor
php artisan l5-swagger:generate            # regenerate the OpenAPI spec
php artisan migrate:fresh --seed          # wipe the DB and reseed
php artisan tinker                         # interactive PHP shell with the app loaded
vendor/bin/pint --dirty --format agent     # format the files you changed
vendor/bin/pint --test                     # check formatting without changing anything
```

## Gotchas you will hit

### `php artisan dev` does not work yet

Laravel 13's combined dev runner starts four processes: `serve`, `queue:listen`, `pail`
for logs, and `vite`. It runs them through `@laravel/multiplex`, which is a Node package
and lives in `node_modules`. That directory does not exist in this project, so the command
fails. Use `php artisan serve` unless you have run `npm install`.

### The web UI needs a build

The scaffold's welcome page used to guard its Vite call and fall back to an inlined
stylesheet, so `GET /` worked without `npm install`. The real UI does not, because
`layouts/app.blade.php` calls `@vite` and Alpine is bundled from `resources/js/app.js`.
Without a build those pages fail on a missing manifest.

For the web UI, run `npm install && npm run build`. `npm run dev` is only for editing the
frontend with hot reload, and the API works without any of this as long as you do not visit
a web page.

### The spec looks stale in the browser

`storage/api-docs/api-docs.json` is gitignored on purpose, because a committed copy drifts
from the attributes it was generated from. If you edit an `#[OA\...]` attribute and
nothing changes in the browser, you skipped this:

```sh
php artisan l5-swagger:generate
```

### 429 from the token endpoint

`POST /api/auth/token` and `POST /api/account` are limited to 6 requests per minute. Wait
60 seconds after hitting that.

### `composer install` fails with a lock file parse error

If you ever see `"./composer.lock" does not contain valid JSON`, check the first lines of
the file for a stray character before `content-hash`. It happened once in this repo and
the fix was a single character. `composer validate --check-lock` catches it.

## Where things live

```text
app/Http/Controllers/Api/   ProductController, TokenController, AccountController
app/Http/Requests/          Validation rules, one class per operation
app/Http/Resources/         How a model is shaped for JSON output
app/Models/                 Product, User
app/Providers/              OpenAPI info and security scheme live here
database/migrations/        Table definitions
database/factories/         Fake data generators for tests and seeds
database/seeders/           What `db:seed` inserts
routes/api.php              Every API route
tests/Feature/Api/          One test file per controller
config/l5-swagger.php       Swagger UI settings
storage/api-docs/           Generated spec, gitignored
```
