<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Every page must declare a layout.
 *
 * This guards a failure that is silent in every other sense. A page missing
 * `defineOptions({ layout })` builds cleanly, imports cleanly, renders its own
 * content perfectly, throws nothing, and logs nothing — it just appears with no
 * sidebar, no topbar and no flash banner around it. It reached production once
 * during this work and was only caught by looking at the rendered page.
 *
 * A browser test would catch it properly, but there is no JavaScript test runner
 * in this project and adding one is a larger decision than this check. Scanning
 * the source costs nothing and fails loudly.
 */
class PageLayoutTest extends TestCase
{
    public function test_every_page_declares_the_admin_layout(): void
    {
        $pages = glob(resource_path('js/pages/*/*.vue')) ?: [];

        $this->assertNotEmpty($pages, 'No page components found; the glob is wrong, not the pages.');

        $undeclared = [];

        foreach ($pages as $page) {
            if (! str_contains((string) file_get_contents($page), 'defineOptions({ layout:')) {
                $undeclared[] = str_replace(resource_path('js/'), '', $page);
            }
        }

        $this->assertSame(
            [],
            $undeclared,
            'A page without a layout renders bare: no sidebar, no topbar, and nothing in the log. '
                .'Add defineOptions({ layout: AdminLayout }).'
        );
    }

    /**
     * The layout has to be named consistently, because the layout option is a
     * direct component reference rather than a string — so a renamed import and
     * a stale defineOptions would resolve to undefined and Vue would warn rather
     * than fail.
     */
    public function test_the_layout_reference_matches_the_import(): void
    {
        foreach (glob(resource_path('js/pages/*/*.vue')) ?: [] as $page) {
            $source = (string) file_get_contents($page);

            $this->assertMatchesRegularExpression(
                '/defineOptions\(\{\s*layout:\s*AdminLayout\s*\}\)/',
                $source,
                str_replace(resource_path('js/'), '', $page).' must reference AdminLayout by name.'
            );

            $this->assertStringContainsString(
                "import AdminLayout from '../../layouts/AdminLayout.vue';",
                $source,
                str_replace(resource_path('js/'), '', $page).' must import AdminLayout from the shared shell.'
            );
        }
    }

    public function test_the_admin_shell_has_a_sidebar_and_a_topbar(): void
    {
        $shell = (string) file_get_contents(resource_path('js/layouts/AdminLayout.vue'));

        $this->assertStringContainsString('<AdminSidebar', $shell);
        $this->assertStringContainsString('<AdminTopbar', $shell);
        $this->assertStringContainsString('<slot />', $shell, 'A layout with no slot shows nothing of the page.');
    }

    /**
     * The sidebar is reachable by id so the topbar's toggle can name it through
     * aria-controls. A control that points at an element that does not exist
     * tells a screen reader the drawer is somewhere it cannot get to.
     */
    public function test_the_sidebar_element_carries_the_id_the_toggle_names(): void
    {
        $this->assertStringContainsString('id="admin-sidebar"', (string) file_get_contents(
            resource_path('js/components/AdminSidebar.vue')
        ));

        $this->assertStringContainsString('aria-controls="admin-sidebar"', (string) file_get_contents(
            resource_path('js/components/AdminTopbar.vue')
        ));
    }
}
