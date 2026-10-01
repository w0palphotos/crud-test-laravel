# Project documentation

A JSON API for a product catalogue, built on Laravel 13 with Sanctum token auth and an
OpenAPI spec that drives Swagger UI.

Written for someone coming from JavaScript. Every code sample is copied from a real file
in this repository, and every command has been run in this environment.

## Start here

| Document | What it covers |
| --- | --- |
| [01. Running the project](01-running-it.md) | Setup, starting the server, the commands you will use daily |
| [02. PHP for JavaScript developers](02-js-to-php.md) | Syntax differences that will actually bite you |
| [03. The request lifecycle](03-request-lifecycle.md) | What happens between a URL and a JSON response |
| [04. Adding a field](04-adding-a-field.md) | Changing a resource end to end, with every file listed |
| [05. Endpoint reference](05-endpoints.md) | Every route, its auth, and its body |
| [06. Auth and tokens](06-auth-and-tokens.md) | How the token flow works and why it is scoped tightly |
| [07. Testing](07-testing.md) | Running the suite and writing new tests |
| [08. Creating an endpoint](08-creating-an-endpoint.md) | Adding a new resource from nothing, generated files to shipped |
| [09. Installing libraries](09-adding-a-library.md) | Composer and npm, `require` versus `require-dev`, publishing config |
| [10. Deploying to Vercel](10-deploying.md) | Serverless function config, external database, the silent failures |
| [11. The web UI](11-the-web-ui.md) | Blade, Alpine, the two auth systems, and what the UI deliberately does not do |

## Which one to read next

If you have not run the project yet, [01](01-running-it.md) first.

If you are new to PHP, [02](02-js-to-php.md) before anything else. Most of the confusion in
a Laravel codebase is PHP confusion wearing a framework costume.

If you are about to build something, [08](08-creating-an-endpoint.md). It is the core task
and it touches every other document. [04](04-adding-a-field.md) is the smaller sibling for
when the thing you are changing already exists.

## Ground rules for this codebase

These are decisions this project makes that you will not find in a generic Laravel
tutorial, because they were deliberate.

**Use `php artisan make:` to create files.** It wires up namespaces, imports, and the
factory or migration stub. Hand-rolling a class usually means a missing `use` line.

**Models use PHP attributes, not docblocks.** Laravel 13 reads `#[Fillable([...])]` above
the class. You will also see older projects use a `protected $fillable = [...]` property.
Both work. This project uses the attribute.

**Routes for resources are declared one per line, not via `apiResource`.** Updates are
partial here, so a `PUT` route would promise full-replacement semantics the controller
does not honour. See [01](01-running-it.md) for the command that shows you the routes.

**There is no `PUT` route.** Only `PATCH`, which means partial update.

**Run Pint before you commit.** `vendor/bin/pint --dirty --format agent`

**Regenerate the OpenAPI spec after editing any `#[OA\...]` attribute.** The spec file is
gitignored, so the browser will show stale output until you do. See
[01](01-running-it.md).

## Versions in this project

Read from this checkout, not from `composer.json` ranges.

| Thing | Installed | Required in composer.json |
| --- | --- | --- |
| PHP | 8.5.10 | `^8.3` |
| laravel/framework | 13.33.0 | `^13.17` |
| laravel/sanctum | 4.3.3 | `^4.0` |
| darkaonline/l5-swagger | 11.1.0 | `^11.1` (dev) |
| phpunit/phpunit | 12.5.36 | `^12.5.12` (dev) |
| laravel/boost | 2.10 | `^2.10` (dev) |
| Database | SQLite at `database/database.sqlite` | `DB_CONNECTION=sqlite` |

Sanctum and l5-swagger are both in `require`, so `composer install --no-dev` keeps the API
and the `/api/documentation` route working. Swagger moved from `require-dev` deliberately:
the documentation page is served in production here, and it broke when it did not.
