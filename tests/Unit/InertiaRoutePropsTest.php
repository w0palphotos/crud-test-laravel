<?php

namespace Tests\Unit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards the boundary between the shared props PHP sends and the props the Vue
 * components read.
 *
 * route() is a Blade helper a Vue component cannot reach, so the URLs the frontend
 * needs are shared by HandleInertiaRequests and read as routes.someKey. That
 * leaves one failure the type checker cannot catch and no HTTP test would catch
 * either: renaming a key in the middleware leaves the Vue side reading
 * undefined, and hrefs and form actions silently become "undefined" or the wrong
 * URL. Nothing errors at build time and every response is a 200.
 *
 * The check is a plain text scan rather than a browser test. It cannot catch a
 * component that hardcodes a path instead of reading one, but it does catch the
 * rename, which is the realistic mistake, and it costs no test runner.
 */
class InertiaRoutePropsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function sharedRoutes(): array
    {
        $this->actingAs(User::factory()->admin()->create());

        return $this->get('/products')->assertOk()->inertiaProps()['routes'];
    }

    /**
     * The route keys each component reads, split by how it reads them.
     *
     * A component that names keys literally — routes.products, routes.account —
     * has its references checked against the shared map, and those names keep the
     * "every shared key is read by something" check honest.
     *
     * A component that indexes the map with a variable — routes[key], which the
     * sidebar does because its items are data — consumes the whole map and is
     * recorded as such. Its keys cannot be enumerated from the source, so treating
     * it as reading nothing would wrongly report every shared key as dead.
     *
     * @return array{literal: array<string, list<string>>, dynamic: list<string>}
     */
    private function routeKeysReadByComponents(): array
    {
        $literal = [];
        $dynamic = [];

        foreach ($this->componentFiles() as $file) {
            $path = $this->relativePath($file);
            $source = file_get_contents($file);

            if (preg_match('#(?<![/\w])routes\s*\[#', $source) === 1) {
                $dynamic[] = $path;

                continue;
            }

            preg_match_all('#(?<![/\w])routes\.([A-Za-z_][A-Za-z0-9_]*)#', $source, $matches);

            if ($matches[1] !== []) {
                $literal[$path] = array_values(array_unique($matches[1]));
            }
        }

        return ['literal' => $literal, 'dynamic' => $dynamic];
    }

    public function test_every_route_key_a_component_reads_is_shared(): void
    {
        $shared = array_keys($this->sharedRoutes());

        foreach ($this->routeKeysReadByComponents()['literal'] as $path => $keys) {
            foreach ($keys as $key) {
                $this->assertContains(
                    $key,
                    $shared,
                    "{$path} reads routes.{$key}, which HandleInertiaRequests does not share. "
                        .'The component will receive undefined and build a URL from nothing.'
                );
            }
        }
    }

    /**
     * Every key a component names in a data-driven list must exist in the map.
     *
     * The check above cannot cover this: a component whose items are declared as
     * data — the sidebar's NAV array — names its keys in plain strings, and indexes
     * the map with a variable, so a source scan of `routes.x` finds nothing.
     *
     * That gap is not theoretical. The sidebar originally carried Laravel route
     * *names* (`sales.index`) where the map holds flat keys (`sales`). Every lookup
     * returned undefined, and Inertia's Link renders an undefined href as the page
     * it is already on, so the entire navigation appeared inert with no error
     * anywhere. These are the keys that has to be checked by name.
     */
    public function test_every_key_named_in_a_component_list_exists(): void
    {
        $shared = array_keys($this->sharedRoutes());

        foreach ($this->componentFiles() as $file) {
            $path = $this->relativePath($file);
            $source = (string) file_get_contents($file);

            // route: 'value' inside a list literal.
            preg_match_all("/route:\s*'([A-Za-z_][A-Za-z0-9_.]*)'/", $source, $matches);

            foreach (array_unique($matches[1]) as $key) {
                $this->assertContains(
                    $key,
                    $shared,
                    "{$path} names routes.{$key} in a list, but HandleInertiaRequests does not share it. "
                        .'An undefined href renders as the current page, so the link looks inert rather than broken.'
                );
            }
        }
    }

    /**
     * The reverse direction matters too: a shared key nothing reads is dead
     * weight in every page response.
     *
     * A component that indexes the map counts as reading all of it, so a key read
     * only through the sidebar's data-driven items is not reported as dead.
     */
    public function test_every_shared_route_is_read_by_something(): void
    {
        $reads = $this->routeKeysReadByComponents();

        $this->assertNotEmpty(
            $reads['dynamic'] || $reads['literal'],
            'No component reads the route map, so this test is scanning nothing. If the map '
                .'moved out of the shared props, delete this rather than leave it passing.'
        );

        // A dynamic consumer stands in for every key at once.
        $read = $reads['dynamic'] !== []
            ? array_keys($this->sharedRoutes())
            : array_merge(...array_values($reads['literal']));

        foreach (array_keys($this->sharedRoutes()) as $key) {
            $this->assertContains(
                $key,
                $read,
                "routes.{$key} is shared on every page but read by no component."
            );
        }
    }

    /**
     * Only the directories holding pages, layouts and components. shared/ is
     * excluded: a helper there is not necessarily mounted, and reading the map is
     * legitimate in one.
     *
     * @return list<string>
     */
    private function componentFiles(): array
    {
        $files = [];

        foreach (['layouts', 'components', 'pages'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(resource_path("js/{$directory}"), \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->getExtension() === 'vue') {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }

    private function relativePath(string $file): string
    {
        return str_replace(resource_path('js/'), '', $file);
    }
}
