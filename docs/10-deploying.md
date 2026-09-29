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
| Read-only filesystem | Not mentioned, and it breaks two routes. See below |
| `php artisan optimize` at build | Do not. Vercel's own guide warns against it |
| The OpenAPI spec | Not mentioned, and it breaks `/api/documentation`. See below |

Two of those are silent failures rather than loud ones, which is why they matter.

## The two silent failures

**Blade cannot compile on a read-only filesystem.** The function filesystem is
read-only at runtime except `/tmp`. Laravel compiles Blade into
`storage/framework/views/`, so the welcome page and the Swagger UI page both
fail while the JSON endpoints keep working, which looks like a routing bug.

Fix it with one environment variable. Laravel's shipped `config/view.php` reads
it, and although this skeleton omits that file, the framework merges its own
defaults, so it still applies. I verified it in this project:

```sh
VIEW_COMPILED_PATH=/tmp/views php artisan tinker --execute 'echo config("view.compiled");'
# /tmp/views
```

**The OpenAPI spec does not exist unless it is in the repository.** The spec
lives at `storage/api-docs/api-docs.json`, and the community runtime gives no
reliable hook to generate it during the build. If the file is not committed,
`/api/documentation` 404s while `/api/products` works fine.

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

```
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

## Environment variables

Add these in the Vercel dashboard under Settings, then Environment Variables.

```
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

Never run `migrate --seed` in production. The seeder is now guarded so it does
not create `test@example.com` outside local, and there is a test pinning that,
but relying on a guard when you do not have to is the wrong instinct.

## Deploying

Push the code first, then import the repository at
`https://vercel.com` through **Add New**, then **Project**.

| Setting | Value |
| --- | --- |
| Framework Preset | Other |
| Build Command | leave empty, the runtime runs `composer install` |
| Output Directory | `public` |
| Install Command | leave empty |

Then Deploy. The first build is where you find out whether the community
runtime resolved your PHP version, since it reads `require.php` from
`composer.json` and yours says `^8.3`.

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

**Throttle the docs route.** `/api/documentation` currently has no rate limit
and will be world-readable. It is one line in `config/l5-swagger.php`:

```php
'middleware' => ['api' => ['throttle:60,1']],
```

**Understand the posture you have chosen.** Public registration plus a public
docs page means anyone can read the full spec, register, and use every endpoint.
The only limits are `throttle:6,1` per IP on `POST /api/account` and
`POST /api/auth/token`. That is coherent for a demo API and wrong for anything
holding real data.

**Keep the spending guard.** Neon's free plan never bills, but if you later
attach a paid resource, set a limit.
