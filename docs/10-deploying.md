# 10. Deploying to Vercel

Vercel has no native PHP runtime. This project runs as a serverless function
using `vercel-php`, a community runtime. The source notes this project started
from are in `docs/test-md/deploy-vercel.md`; this document is the corrected
version, with the gaps filled and the wrong bits fixed.

## What the research got wrong, and what it missed

| Source notes | Reality |
| --- | --- |
| `vercel-php@0.6.2` | `0.9.0` is the current npm `latest` dist-tag |
| `require __DIR__ . '/../public/index.php';` | Works, but use `__DIR__` without spaces around the `.` and resolve it explicitly rather than relying on the working directory |
| `SESSION_DRIVER` to cookie or Redis | Not needed. This API is token-only, `auth:sanctum` never touches the session, and `database` works because the database is external |
| Migrations | Not mentioned. Vercel has no shell, so `php artisan migrate --force` must be run from your machine against the production database |
| Read-only filesystem | Not mentioned, and it breaks three things. See below |
| `php artisan optimize` at build | Do not. Vercel's own guide warns against it |
| The OpenAPI spec | Not mentioned, and it breaks `/api/documentation`. See below |
| Composer runs with `--no-scripts` | Not mentioned, and it produces a blank 500 on every route. See below |

Three of those are silent failures rather than loud ones, which is why they
matter.

## The three silent failures

**The framework caches never exist, and Laravel insists on writing them.** This
is the one that returns a blank white 500 with no error message, so read it
first. `vercel-php` runs composer with these flags, hardcoded in its
`runComposerInstall`:

```text
install --profile --no-dev --no-interaction --no-scripts --ignore-platform-reqs --no-progress
```

`--no-scripts` means `package:discover` never runs during the build, so
`bootstrap/cache/packages.php` is never created. It cannot arrive from git
either, because `bootstrap/cache/.gitignore` is `*`. On the first request
`PackageManifest` finds the file missing and tries to write it:

```php
// Illuminate/Foundation/PackageManifest.php
if (! is_file($this->manifestPath)) {
    $this->build();          // -> write()
}

// write()
if (! is_writable($dirname = dirname($this->manifestPath))) {
    throw new Exception("The {$dirname} directory must be present and writable.");
}
```

The filesystem is read-only, so it throws. The handler then tries to render
that exception as a Blade view into a directory that is also read-only, so the
handler dies mid-response and PHP emits its own default 500: `text/html`,
`content-length: 0`. Every route fails identically, including `/up`.

You can recognise it in one request. Laravel returns JSON for `api/*` paths, so
a request to `/api/products` that comes back as `text/html` with an empty body
means the exception handler never ran.

The fix is the runtime's documented build hook, a script named `vercel` in
`composer.json`. `runComposerScripts` invokes it *without* `--no-scripts`, so it
does execute:

```json
"scripts": {
  "vercel": ["@php artisan package:discover --ansi"]
}
```

Booting artisan also writes `bootstrap/cache/services.php` as a side effect via
`ProviderRepository`, so one command produces both caches. The runtime harvests
`glob('**')` from the build directory *after* this runs, so the generated files
are bundled into the function. Verify it worked by looking for `Discovering
packages` in the build log.

Note that `.vercelignore` is **not** reapplied during packaging. It only
controls what gets uploaded, which is why excluding `/vendor` is correct: the
runtime reinstalls it and harvests the result.

Do not add `config:cache` to that script. It would serialise config while
`DB_*` and `APP_KEY` are still absent, freezing an empty database config and a
null key into the deployment. Environment variables only exist per request on
serverless. `route:cache` fails for a different reason: `routes/web.php`
registers `/` as a closure, which route caching cannot serialise.

**Blade cannot compile on a read-only filesystem.** On the community runtime the
function filesystem was read-only at runtime except `/tmp`, so Laravel's Blade
compiler, which writes to `storage/framework/views/`, could not run. The welcome
page and the Swagger UI page failed while the JSON endpoints kept working, which
looks like a routing bug.

The container runtime has a normal writable filesystem, so this failure mode is
gone by construction rather than by configuration. `VIEW_COMPILED_PATH` is still
harmless and still worth setting, and it remains the right variable if you ever
return to a runtime with a read-only filesystem, but it is no longer load-bearing
here.

**The OpenAPI spec does not exist unless it is in the repository.** The spec
lives at `storage/api-docs/api-docs.json`, and neither runtime regenerates it
reliably at build time. If the file is not committed, `/api/documentation` 404s
while `/api/products` works fine.

That is why `storage/api-docs/api-docs.json` is committed in this project even
though it was originally gitignored. The cost is drift, so after changing any
`#[OA\...]` attribute:

```sh
php artisan l5-swagger:generate
git add storage/api-docs/api-docs.json
```

## Database

Vercel hosts no databases. Use Neon, which is free with no expiry, no card,
0.5GB, and compute that scales to zero after 5 minutes.

Create one project and one branch, then take the **pooler** connection string.
You set these yourself on Vercel, because the platform injects nothing:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=<neon pooler host>
DB_PORT=5432
DB_DATABASE=<database>
DB_USERNAME=<user>
DB_PASSWORD=<password>
```

`DB_CONNECTION` is the one that catches people. `config/database.php:20` reads
`env('DB_CONNECTION', 'sqlite')`, so leave it unset and the app silently targets
SQLite, which cannot work here because the filesystem is read-only. That
failure is loud, which is a small mercy.

### Supabase

Nothing in this app is Neon-specific; `pgsql` is just `pgsql`. Supabase works,
but it is not a straight string swap, and it needs **two different connection
strings**: one for the deployed application and a different one for migrations.

| Purpose | Connection mode | Port | Why |
| --- | --- | --- | --- |
| Deployed app | Shared pooler, transaction | 6543 | IPv4, and a serverless function opens many short-lived connections |
| Migrations | Direct | 5432 | A migration is a single long-lived session and needs real Postgres features |

Get each from the dashboard's **Connect** dialog, choosing the matching mode
and the **URI** format toggle rather than JDBC or node.js.

**The app connection.** Supabase's direct connection is IPv6-only, which is the
most common cause of total connection failure from a Vercel function. The
shared transaction pooler is IPv4 on every plan. It has a different username
from a direct connection, `postgres.PROJECTREF` rather than `postgres`, and a
host you cannot derive from your region, because `[INDEX]` in
`aws-0-eu-central-1.pooler.supabase.com` is a pooler cluster index rather than
part of the region name. Copy both from the dialog.

Two query parameters are required:

- `sslmode=require`. The shipped default is `prefer` (`config/database.php`),
  which will fall back to plaintext, and Supabase requires TLS.
- `options=--statement_cache_size=0`. Transaction mode is PgBouncer and does
  not support named prepared statements, which `pdo_pgsql` uses, so you get
  `prepared statement ... already exists` under concurrent load.

Laravel takes the whole string in one variable, and query parameters win over
the discrete defaults because `ConfigurationUrlParser` merges them last:

```dotenv
DB_CONNECTION=pgsql
DB_URL=postgresql://postgres.PROJECTREF:PASSWORD@POOLER-HOST:6543/postgres?sslmode=require&options=--statement_cache_size%3D0
```

The URL's `host`, `port`, `database`, `username`, and `password` all overwrite
the `DB_*` defaults, so the five discrete variables are not needed. Percent-encode
any reserved characters in the password; the dashboard's URI toggle does this
for you.

**The migration connection.** Use the direct string, and do not carry the
pooler parameters across:

```sh
export DB_URL='postgresql://postgres:PASSWORD@db.PROJECTREF.supabase.co:5432/postgres?sslmode=require'
php artisan migrate --force
```

This is the step that is easy to get wrong, because it is a *different* string
from the one in Vercel. If your machine is on an IPv4-only network the direct
connection will not resolve, and the session pooler (port 5432,
`postgres.PROJECTREF`) works there instead; migrations do not need transaction
mode.

One behavioural note: on the free tier Supabase pauses an idle project, so the
first request after a spell of inactivity can hang or 5xx while it wakes.

## Environment variables

Add these in the Vercel dashboard under Settings, then Environment Variables.

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:...            # from: php artisan key:generate --show
APP_URL=https://your-app.vercel.app
LOG_CHANNEL=stderr            # required, see below
VIEW_COMPILED_PATH=/tmp/views # required, see above
DB_CONNECTION=pgsql
DB_HOST=...
DB_PORT=5432
DB_DATABASE=...
DB_USERNAME=...
DB_PASSWORD=...
```

If you use Supabase, replace the five `DB_*` lines above with the single
`DB_URL` from the section above. `DB_CONNECTION=pgsql` still has to be set.

**Do not paste `.env.example` into the dashboard.** It is a local SQLite
template, so it supplies `DB_CONNECTION=sqlite` and none of the credentials a
hosted database needs. The result is a silent fallback to a local database file
that does not exist on the host, and the first symptom is confusing: `GET /docs`
returns 500 while `GET /api/products` returns 401, because the spec route runs a
rate limiter that reads the cache table before the controller is reached. Set
each variable deliberately instead.

Two of those are not optional and neither appears in the source notes.

`LOG_CHANNEL=stderr` because `stack`/`single` writes to
`storage/logs/laravel.log`, which is read-only. Without it, anything that logs
fails.

`VIEW_COMPILED_PATH=/tmp/views` for the reason above.

Note that `key:generate` on its own only writes to your local `.env`. Use
`--show` to print the value you need to paste.

`CACHE_STORE`, `SESSION_DRIVER`, and `QUEUE_CONNECTION` can stay `database`.
Nothing dispatches queued jobs, so no worker is needed. No Redis required.

## Migrations

Vercel gives you no shell, so run them from your machine against the production
database:

```sh
php artisan migrate --force
```

Do this once, after the first successful deploy. Vercel's own guide is explicit
that migrations should be a separate release step and never run at container
startup, because concurrent instances would race.

Use a **direct** connection for migrations, not the transaction pooler the
application uses. A migration is one long-lived session and needs session-level
state, which transaction mode discards between transactions. See the Supabase
section above for both strings.

`migrate --seed` is safe in production, and useful for a demo. The seeder
creates 10 products in every environment and creates the `test@example.com`
user only outside production, because that account's password is the literal
string `password` and `POST /api/account` is public, so seeding it would be a
known-credential backdoor. Three tests pin that split, including
`test_it_still_seeds_products_in_production`.

## Deploying

Push the code first, then import the repository at
`https://vercel.com` through **Add New**, then **Project**.

| Setting | Value |
| --- | --- |
| Framework Preset | Other |
| Install Command | leave empty |
| Build Command | leave empty |
| Output Directory | leave empty |

All three must be empty, and that is not a convenience. `vercel.json` declares a
`services` entry with `"runtime": "container"` and `"entrypoint":
"Dockerfile.vercel"`, so Vercel builds the image and ignores install and build
commands entirely. Setting a build command overrides the Dockerfile's own build
steps, which is how the Vite assets end up missing.

Npm and Composer both run *inside* the image: a Node stage builds the assets and
a Composer stage installs the dependencies. The stages in order matter. Composer
`install` runs from the lock file with `--no-scripts`, and the autoloader is
dumped only after the application is copied, because an authoritative autoloader
generated before the vendor tree exists contains just this application's own
classes and the image then fails to boot.

Then Deploy. Two things are worth reading in the build log rather than assuming:

- `Discovering packages` confirms the `vercel` hook ran. If it is missing, the
  framework caches were not generated and every route will 500.
- `Installing dependencies from lock file` confirms composer resolved from
  `composer.lock`. Because the runtime passes `--ignore-platform-reqs`, a PHP
  version or extension mismatch will *not* fail the build; it surfaces as a
  runtime error instead.

## Verifying it actually works

Work in this order, because each step isolates a different failure. Stop at the
first failure rather than debugging the one after it.

```sh
BASE=https://your-app.vercel.app

# 1. JSON works at all
curl -s -o /dev/null -w "products: %{http_code}\n" -H 'Accept: application/json' $BASE/api/products
#    expect 401, not 500. 401 proves routing, boot, and error handling are fine

# 2. the database is not SQLite
curl -s -H 'Accept: application/json' $BASE/api/account
#    expect 401. A 500 here means DB_CONNECTION is wrong

# 3. Blade renders, which is the read-only-filesystem test
curl -s -o /dev/null -w "docs: %{http_code}\n" $BASE/api/documentation
#    expect 200. Anything else means VIEW_COMPILED_PATH is missing

# 4. the full flow, including the Authorization header
curl -s -X POST $BASE/api/account -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"name":"Ada","email":"ada@example.com","password":"correct-horse-battery","password_confirmation":"correct-horse-battery"}'
#    expect 201 and a token

curl -s -H "Authorization: Bearer <token>" -H 'Accept: application/json' $BASE/api/products
#    expect 200
```

Step 4 is the one that catches a stripped `Authorization` header. If step 1
gives 401 but step 4 also gives 401 with a valid token, the header is not
reaching PHP. The `public/.htaccess` file has a rewrite rule that re-injects it
for Apache, and it is irrelevant here, because Vercel does not read
`.htaccess`. If you hit this, the fix is in the runtime's header handling, not
in Laravel.

## The official alternative

Vercel published its own Laravel guide on 2026-09-03, three weeks before this
was written, and it does not use the community runtime. It uses Docker with
FrankenPHP:

| | `vercel-php` community runtime | Docker + FrankenPHP, Vercel's guide |
| --- | --- | --- |
| PHP version | Read from `require.php`, unverified | Pinned in the Dockerfile |
| PHP extensions | Not under your control | You install them, e.g. `pdo_pgsql` |
| Compiled views | `VIEW_COMPILED_PATH` env var | `view:cache` during the build |
| Build hook for the spec | None, so the spec is committed | Full, so the spec can stay ignored |
| Official support | None | Yes |

The Docker route is more files and more to understand, and it is the one to move
to if the community runtime fights you. Vercel has a plugin for it:

```sh
npx plugins add vercel/vercel-plugin
```

If you go that way, the three files are `Dockerfile.vercel`, `Caddyfile`, and a
`vercel.json` using `"services"` with `"runtime": "container"`. Read
https://vercel.com/kb/guide/laravel-php-with-vercel first, and note that its
Dockerfile installs `pdo_pgsql` or `pdo_mysql` explicitly, which the community
runtime does for you.

## Things worth doing before this faces the internet

**Rate limits are already in place.** `GET /docs` is limited to `throttle:60,1`
per client IP, and `POST /api/account` and `POST /api/auth/token` are limited to
`throttle:6,1`. The Swagger UI page at `/api/documentation` is deliberately
unlimited, because it is a static shell that fetches the spec once.

That limit is per client IP, which is only meaningful because
`bootstrap/app.php` calls `trustProxies(at: '*')`. Without it Laravel ignores
`X-Forwarded-For` behind Vercel's load balancer, every visitor resolves to the
same proxy address, and all traffic collapses into a single shared bucket that
would 429 the whole internet after 60 requests a minute. `X-Forwarded-For` is
already in Laravel's default trusted header set, so no header list is needed.

The tradeoff: a client that rotates `X-Forwarded-For` can evade the limit, so
this deters casual scraping rather than a determined scraper.

**Understand the posture you have chosen.** Public registration plus a public
docs page means anyone can read the full spec, register, and use every endpoint.
That is coherent for a demo API and wrong for anything holding real data.

**Keep the spending guard.** Neon's free plan never bills, but if you later
attach a paid resource, set a limit.
