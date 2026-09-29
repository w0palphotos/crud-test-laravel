# 09. Installing and using libraries

Two package managers, one rule: **prefer what is already installed, and the standard
library, over a new dependency.** This document is mostly about that rule, with the commands
when a new package is genuinely the right answer.

## The JavaScript parallel

You already know this shape, it just has different names:

| JavaScript | PHP | Composer |
| --- | --- | --- |
| `npm install lodash` | add a package | `composer require vendor/package` |
| `dependencies` | runtime packages | `require` |
| `devDependencies` | build and test tooling | `require-dev` |
| `node_modules/` | installed packages | `vendor/` |
| `package-lock.json` | exact resolved versions | `composer.lock` |
| `npm ci` | install exactly what is locked | `composer install` |
| `node_modules` is gitignored | same | `vendor` is gitignored |

So the equivalence you want is:

```sh
npm install some-package   ->  composer require some-package
npm install -D some-tool   ->  composer require --dev some-tool
npm ci                     ->  composer install
```

**`composer install` and `npm ci` are not the same thing.** `composer install` installs
exactly what `composer.lock` pins and never rewrites the lock. If `composer.json` has
changed since the lock was written, it warns that the lock is stale and installs the old
versions anyway. `composer update` is the command that re-resolves and rewrites
`composer.lock`. Keeping those straight saves a lot of confusion.

## What this project has installed

Read from `composer.lock`, not guessed:

| | Count |
| --- | --- |
| Direct, `require` | 4 |
| Direct, `require-dev` | 9 |
| Locked, runtime | 77 |
| Locked, dev | 44 |
| **Total locked** | **121** |

Four direct runtime packages, one hundred and twenty-one actual. That ratio is normal. Adding
one package often pulls in a dozen, because PHP packages lean on `symfony/*` components.

The 13 direct ones:

| Package | Section | Why it is here |
| --- | --- | --- |
| `laravel/framework` | require | The framework |
| `laravel/tinker` | require | `php artisan tinker` |
| `laravel/sanctum` | require | Token auth. Added by `install:api` |
| `darkaonline/l5-swagger` | require-dev | Swagger UI and the OpenAPI generator |
| `laravel/boost` | require-dev | Agent guidelines, added because `AGENTS.md` said to |
| `laravel/pint` | require-dev | The formatter |
| `phpunit/phpunit` | require-dev | The test runner |
| `fakerphp/faker` | require-dev | Fake data for factories |
| `mockery/mockery` | require-dev | Mocking |
| `nunomaduro/collision` | require-dev | Pretty CLI errors |
| `laravel/pail` | require-dev | Log tailing |
| `laravel/pao` | require-dev | Output formatting for agents. See the warning in step 5 |

`laravel/sanctum` is the only one added for the API itself. `fakerphp/faker`,
`phpunit/phpunit`, and `mockery/mockery` were already in the Laravel skeleton.

## Which section, require or require-dev

The rule is simple. If the app needs it when it serves a request, it is `require`. If it is
only for building, testing, or local tooling, it is `require-dev`.

```sh
composer require vendor/package           # runtime
composer require --dev vendor/tool        # build, test, or local only
```

Both are installed by a plain `composer install`. The difference is `composer install
--no-dev`, which skips the dev section. Run that dry to see what it would do here:

```sh
composer install --no-dev --dry-run
```

```text
Package operations: 0 installs, 0 updates, 44 removals
  - Removing zircote/swagger-php (6.11.0)
  - Removing swagger-api/swagger-ui (v5.33.0)
  - ...
```

Forty-four packages go, and among them `zircote/swagger-php` and `swagger-api/swagger-ui`.
So a production install has no Swagger UI and no `/api/documentation` route. That is the
correct trade for this project, and it is why l5-swagger is a dev dependency: the API is the
deliverable, the docs page is a development tool.

If you ever find yourself wanting `/api/documentation` in production, move l5-swagger to
`require`. Do not do it by accident, because the docs page has no authentication on it in
this project.

## Scaffolding commands versus manual installs

There are two ways a package gets in, and the difference matters.

### Manual, the Composer way

```sh
composer require --dev darkaonline/l5-swagger --no-interaction
php artisan vendor:publish --provider "L5Swagger\L5SwaggerServiceProvider"
```

That is two commands and you know exactly what happened. `vendor:publish` copied two things
into your project:

```text
vendor/darkaonline/l5-swagger/config/l5-swagger.php  ->  config/l5-swagger.php
vendor/darkaonline/l5-swagger/resources/views        ->  resources/views/vendor/l5-swagger
```

You now own those files. Laravel does not overwrite them on upgrade, so if a future version
of l5-swagger changes its config, you do not get the change. That is the cost of publishing,
and the benefit is that you can change the settings.

### Scaffolding, the Artisan way

```sh
php artisan install:api --without-migration-prompt
```

That one command did four things at once: added `laravel/sanctum` to `composer.json`,
published the `personal_access_tokens` migration, created `routes/api.php`, and added this
line to `bootstrap/app.php`:

```php
api: __DIR__.'/../routes/api.php',
```

It then printed a reminder to do the one thing it would not do for you:

```text
Please add the [Laravel\Sanctum\HasApiTokens] trait to your User model.
```

That is worth pausing on. **A scaffolding command can install code and still leave the
feature broken.** It does not add the trait, does not add an auth route, and does not
restrict any endpoints. The API was not usable until those were done by hand.

So after any `install:*` command, read what it printed and check the feature actually
works. Do not assume the command finished the job.

## The publishable tags

Each package decides what it lets you copy. To see what is available, run it with no
arguments:

```sh
php artisan vendor:publish
```

With neither `--provider` nor `--tag`, it opens an interactive picker listing every
provider and every tag, which is the reliable way to discover the names. To publish one
specific thing:

```sh
php artisan vendor:publish --tag=sanctum-config --no-interaction
php artisan vendor:publish --provider="L5Swagger\L5SwaggerServiceProvider" --no-interaction
```

The relevant tags here, read from `ServiceProvider::$publishGroups`:

| Tag | Copies |
| --- | --- |
| `config` (l5-swagger) | `config/l5-swagger.php` |
| `sanctum-config` | `config/sanctum.php` |
| `sanctum-migrations` | the `personal_access_tokens` migration |
| `boost-config` | `config/boost.php` |
| `laravel-assets` | front-end assets. Reported "no publishable resources" in this project |

Or read the groups without the interactive prompt:

```sh
php artisan tinker --execute 'print_r(array_keys(\Illuminate\Support\ServiceProvider::$publishGroups));'
```

Publishing a config file is how you change a package's behaviour without editing `vendor/`.
Edit `config/`, never `vendor/`, because `composer install` will replace anything in
`vendor/` and your edit disappears with no warning.

### Do not run `vendor:publish` with an empty tag

`--tag` is a variadic option, so `--tag=` becomes `[""]`, which counts as "a tag was
given". That skips the prompt and publishes **everything every package provides**, with no
confirmation.

I did this by accident while writing this section. It created 17 files that had no business
in this project, including a second copy of the `personal_access_tokens` migration. Because
two migrations then created the same table, 43 of the 45 tests failed with:

```text
SQLSTATE[HY000]: General error: 1 table "personal_access_tokens" already exists
```

The recovery was `rm` on the published files and a re-run of the suite. Nothing was
committed, so it cost time rather than history. If you hit it, check `git status` first,
since every published file is untracked and `git clean` style rollback is safe.

`--all` does the same thing deliberately. Use it only when you have decided you want every
package's assets.

## Node packages

```sh
npm install
npm install --save-dev some-package
npm run build
```

`node_modules` is not installed in this project. The API and Swagger UI do not need it. The
one exception is the front end: `php artisan dev` runs `vite`, and `npm run build` produces
the `public/build/manifest.json` that `resources/views/welcome.blade.php` checks for.

`package.json` in this project declares Vite, Tailwind, and `concurrently`. `concurrently`
is declared but there is no `postinstall` script, so nothing installs packages for you.

## A warning about `laravel/pao`

Creating PHP files in this project can make `laravel/pao`, a dev dependency, modify your
Node setup. I triggered it accidentally while writing these docs. Running
`make:controller` caused it to:

- add `prettier` and `@prettier/plugin-php` to `package.json` devDependencies
- create `.prettierrc` with `singleQuote: true` and a `printWidth: 1000` PHP override
- run npm and write a 95KB `package-lock.json`

It did not create `node_modules`. The `.prettierrc` it wrote sets `printWidth` to 1000 for
PHP, which is the opposite of `laravel/pint` at 100. If you install it, you now have two
formatters with conflicting opinions and Pint is the one the project actually runs.

Check `git status` after any command that creates PHP files, and decide what you want:

```sh
git checkout -- package.json && rm -f .prettierrc package-lock.json
```

## Verifying a package actually works

The gotcha that will waste your afternoon if you do not know it. This is a **false
negative**:

```sh
php -r 'echo class_exists("Illuminate\Database\Eloquent\Attributes\Fillable") ? "exists" : "MISSING";'
# MISSING
```

The class exists. I hit this while writing these docs and nearly reported a missing class.
Standalone `php -r` has no Composer autoloader registered, so `class_exists` cannot find
anything in `vendor/`. Load the autoloader first:

```sh
php -r 'require "vendor/autoload.php"; echo class_exists("Illuminate\Database\Eloquent\Attributes\Fillable") ? "exists" : "MISSING";'
# exists
```

Two better checks, both of which go through the framework:

```sh
php artisan tinker --execute 'echo class_exists(App\Models\Product::class) ? "ok" : "no";'
php artisan about
```

And if a package registers routes, confirm they arrived:

```sh
php artisan route:list --path=api -v
```

`route:list` is the highest-signal check for a web package. A route pointing at
`L5Swagger\Http\Controllers\SwaggerController@api` is the package's. A route pointing at
`Api\ProductController@index` is yours. [08](08-creating-an-endpoint.md) covers the
difference.

## Removing a package

```sh
composer remove --dev darkaonline/l5-swagger --no-interaction
```

Then clean up what it left behind, because `composer remove` does not do this:

- [ ] `config/l5-swagger.php`, if you had published it
- [ ] `resources/views/vendor/l5-swagger/`
- [ ] every `use OpenApi\Attributes as OA;` and `#[OA\...]` attribute in your code
- [ ] the route, if you registered one by hand
- [ ] `vendor/bin/pint --dirty`, since removing an import leaves a blank line

Check what it pulled in transitively before you assume it all went:

```sh
composer why zircote/swagger-php
# darkaonline/l5-swagger 11.1.0 requires zircote/swagger-php (^6.0)
```

`composer why` walks the tree backwards from a package to whoever needs it. Use it before
removing anything, to find out whether something else depends on it.

## Before adding a dependency, check these first

In order:

1. **Is it already installed?** `composer show --direct` lists the 13 direct ones. A
   transitive package is already on disk and you can use it, though relying on it is
   fragile, since nothing guarantees it stays.
2. **Does PHP itself do it?** PHP 8.5 has `array_find`, `array_any`, `array_all`,
   `json_validate`, and pipe syntax. Check before assuming a package is needed.
3. **Does Laravel do it?** Eloquent, Collections, the validator, the scheduler, and queues
   cover a lot of ground that would otherwise need a package.
4. **Does the framework skeleton do it?** `php artisan make:` commands, `install:*`
   commands, and `php artisan list` cover a lot of scaffolding.
5. **Only then**, add the package.

The reason this matters is not purity. Every dependency is code you did not write, that
keeps needing updates, and that can conflict with the next one. A CRUD API in this project
needed exactly one new runtime package. That is a good ratio.

## Common commands

```sh
composer require vendor/package
composer require --dev vendor/tool
composer remove --dev vendor/tool
composer install
composer install --no-dev
composer update vendor/package
composer update --lock
composer show
composer show --direct
composer show vendor/package          # one package in detail
composer why vendor/package            # who needs this
composer why-not vendor/package        # why is it not installed
composer outdated                      # newer versions available
composer validate --check-lock         # is composer.json sane and the lock in sync
composer audit                         # known security advisories
composer dump-autoload                 # regenerate the autoloader after adding files by hand
```

Two of those deserve emphasis. `composer audit` is the reason to run it, since it checks
installed versions against the advisory database. And `composer validate --check-lock` is
the one-liner that tells you whether `composer.json` and `composer.lock` agree.

Note the naming: `composer show` is `npm list`, `composer why` is `npm why`, and
`composer outdated` is `npm outdated`.
