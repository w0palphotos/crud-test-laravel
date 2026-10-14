import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * The route map HandleInertiaRequests shares.
 *
 * Reading it from the shared props is what keeps route definitions in
 * routes/web.php. A component that built a path by string concatenation would be
 * a second place to change every time a route moved, and the failure mode is a
 * link that looks fine and 404s.
 *
 * Returned as a plain object rather than a ref. The values behind it are already
 * reactive, and a ref would force `.value` in script while templates unwrapped it
 * for free — two spellings of the same access, and the one that silently yields
 * undefined in a script is the one nobody checks.
 */
export function useRoutes() {
    return usePage().props.routes;
}

/**
 * Whether the given absolute route URL is the one currently being viewed.
 *
 * A prefix match rather than equality, so /products/12/edit still counts as being
 * on the products section. The trailing separator is what stops /products-archive
 * from matching /products.
 */
export function useRouteMatcher() {
    const page = usePage();

    const current = computed(() => pathname(page.url));

    return function matches(url) {
        const target = pathname(url);

        return current.value === target || current.value.startsWith(`${target}/`);
    };
}

function pathname(url) {
    return new URL(url, window.location.origin).pathname;
}