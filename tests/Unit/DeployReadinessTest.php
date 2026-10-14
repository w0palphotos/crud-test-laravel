<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Checks that run before a deploy, against the artefacts rather than the code.
 *
 * Every test here guards a failure that shares a shape: the application is
 * correct, it builds, and every existing test passes, yet the deployed result is
 * wrong. That is why they are not in the ordinary suite — most of them assert
 * that something which is usually true stays true, so running them on every
 * save would be noise. Run them when a deploy is imminent:
 *
 *     php artisan test --group=deploy
 *
 * They are fast and they touch neither the database nor the network, because a
 * gate that needs infrastructure is a gate people start skipping.
 *
 * Composer's own equivalent — the content-hash in composer.lock — is deliberately
 * not asserted. The algorithm is Composer's internal, it is not reachable from
 * vendor/ (the class ships with the Composer binary, not with the project), and a
 * mismatch is a warning rather than a build failure. The npm lock is checked
 * instead, because Dockerfile.vercel runs `npm ci`, and that does hard-fail.
 */
#[Group('deploy')]
class DeployReadinessTest extends TestCase
{
    /**
     * The committed specification must describe the routes that actually exist.
     *
     * storage/api-docs/api-docs.json is committed on purpose because no runtime
     * regenerates it reliably at build time; nothing rebuilds it on Vercel, so
     * the file in the repository is the file the deployed application serves. The
     * cost of that decision is drift, and drift is silent: adding a route and
     * forgetting to regenerate leaves /api/documentation answering confidently
     * about an endpoint set that no longer matches what the API does.
     *
     * Read from the router rather than by parsing routes/api.php, so the
     * assertion is about what Laravel registered, not about how the source is
     * spelled. Compared by path rather than by path-and-method, because the
     * interesting case is a whole path missing, not a single verb on it.
     *
     * Scoped to the application's own controllers. l5-swagger registers
     * /api/documentation and /api/oauth2-callback through its service provider,
     * so they arrive on the api prefix like everything else while belonging to
     * nobody's routes/api.php — and the spec is not supposed to describe the
     * documentation page that serves it.
     */
    public function test_committed_openapi_spec_covers_every_api_route(): void
    {
        $registered = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/'))
            ->filter(fn ($route): bool => str_starts_with($route->getActionName(), 'App\\'))
            ->map(fn ($route): string => '/'.trim($route->uri(), '/'))
            ->unique()
            ->sort()
            ->values()
            ->all();

        $this->assertNotEmpty($registered, 'No API routes were registered; the test is wired wrong, not the application.');

        $spec = $this->spec();

        $undocumented = array_values(array_diff($registered, array_keys($spec['paths'])));

        $this->assertSame(
            [],
            $undocumented,
            'These routes are live but absent from the committed spec, so /api/documentation '
            .'understates the API. Regenerate and commit it: php artisan l5-swagger:generate && '
            .'git add storage/api-docs/api-docs.json'
        );

        $orphaned = array_values(array_diff(array_keys($spec['paths']), $registered));

        $this->assertSame(
            [],
            $orphaned,
            'The committed spec documents paths that no longer exist, so /api/documentation '
            .'advertises endpoints that 404. Regenerate and commit it.'
        );
    }

    /**
     * The built asset manifest must exist and name the application entry.
     *
     * public/build is gitignored and built inside the image, so a working tree
     * that has never been built has no manifest at all — and @vite throws
     * "Unable to locate file in Vite manifest" on every page, which reads as a
     * broken application rather than a missing build step. Catching it here means
     * the failure arrives before the deploy rather than after it.
     *
     * This asserts the manifest is present, not that it is fresh. A manifest from
     * yesterday still satisfies it; nothing short of rebuilding proves freshness,
     * and rebuilding belongs to the build, not to a test.
     */
    public function test_vite_manifest_exists_with_the_app_entry(): void
    {
        $manifest = public_path('build/manifest.json');

        $this->assertFileExists(
            $manifest,
            'public/build/manifest.json is missing. Run: npm run build'
        );

        $entries = json_decode((string) file_get_contents($manifest), true);

        $this->assertIsArray($entries, 'The manifest did not decode as JSON; the build produced it broken.');

        $this->assertArrayHasKey(
            'resources/js/app.js',
            $entries,
            'The manifest has no entry for resources/js/app.js, so app.blade.php cannot resolve its entrypoint.'
        );
    }

    /**
     * package.json and the lock file must agree.
     *
     * Dockerfile.vercel copies both files and runs `npm ci` in its assets stage,
     * which resolves strictly from package-lock.json and hard-fails when the two
     * disagree. The Docker build is therefore the first place a divergence is
     * noticed, at the most expensive moment, after the push.
     *
     * Versions are compared as written. `^7.1.0` and `7.1.0` satisfy each other
     * for npm but are not the same requirement, and a lock that records a
     * different requirement than the manifest is what `npm ci` rejects.
     */
    public function test_the_npm_lock_agrees_with_package_json(): void
    {
        $manifest = $this->readJsonFile(base_path('package.json'));
        $lock = $this->readJsonFile(base_path('package-lock.json'));

        $this->assertArrayHasKey(
            'lockfileVersion',
            $lock,
            'package-lock.json has no lockfileVersion; it is not a lock file this check understands.'
        );

        $recorded = $lock['packages'][''] ?? [];

        foreach (['dependencies', 'devDependencies'] as $section) {
            $declared = $manifest[$section] ?? [];
            $locked = $recorded[$section] ?? [];

            $this->assertSame(
                $declared,
                $locked,
                "package.json and package-lock.json disagree about {$section}. "
                .'Run: npm install, then commit both files. `npm ci` in Dockerfile.vercel fails on this.'
            );
        }
    }

    /**
     * Every key the example environment file documents must be one the
     * application reads.
     *
     * .env.example is the checklist someone follows when configuring Vercel, and a
     * key on it that nothing reads is worse than a missing one: it invites a
     * deployment to set a variable that cannot affect anything, and it hides the
     * fact that the behaviour it implies is not configured at all.
     *
     * The check is one-directional on purpose. Requiring every env() in config/
     * to appear here would mean listing the ~44 keys Laravel's own config reads
     * without a default — memcached credentials, cache lock tables — none of
     * which this application uses. A list that long stops being read, and a
     * check that is not read is not a gate.
     *
     * Both the application's config and the framework's own defaults are scanned.
     * Laravel merges the files in vendor/laravel/framework/config/ whether or not
     * this application publishes them, so BCRYPT_ROUNDS and BROADCAST_CONNECTION
     * are genuinely read from hashing.php and broadcasting.php that live in
     * vendor. Scanning only config/ would report both as dead, which is the same
     * kind of false alarm the test exists to prevent.
     *
     * Keys the frontend reads through import.meta.env are out of scope: they are
     * invisible from PHP, and VITE_APP_NAME is the one this test names rather
     * than silently ignoring.
     */
    public function test_every_documented_env_key_is_read_by_a_config_file(): void
    {
        $sources = array_merge(
            glob(config_path('*.php')) ?: [],
            glob(base_path('vendor/laravel/framework/config/*.php')) ?: [],
        );

        $read = [];

        foreach ($sources as $file) {
            preg_match_all("/env\(\s*'([A-Z0-9_]+)'/", (string) file_get_contents($file), $matches);

            foreach ($matches[1] as $key) {
                $read[$key] = true;
            }
        }

        $this->assertNotEmpty(
            $read,
            'No env() key was found in either directory; the scan is broken, not the application.'
        );

        $orphaned = array_values(array_diff($this->documentedEnvKeys(), array_keys($read)));

        $this->assertSame(
            [],
            $orphaned,
            'These keys are documented in .env.example but read by no config file, so setting them '
            .'in Vercel changes nothing. Remove them from .env.example, or read them in config/.'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function spec(): array
    {
        $path = base_path('storage/api-docs/api-docs.json');

        $this->assertFileExists(
            $path,
            'storage/api-docs/api-docs.json is missing. It is committed on purpose because nothing '
            .'regenerates it on deploy; regenerate it: php artisan l5-swagger:generate'
        );

        return $this->readJsonFile($path);
    }

    /**
     * @return array<string, mixed>
     */
    private function readJsonFile(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded, "{$path} did not decode as JSON.");

        return $decoded;
    }

    /**
     * The assignment lines of .env.example, comments and blanks aside.
     *
     * @return list<string>
     */
    private function documentedEnvKeys(): array
    {
        $keys = [];

        foreach (file(base_path('.env.example'), FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }

            $keys[] = trim(explode('=', $line, 2)[0]);
        }

        return $keys;
    }
}
