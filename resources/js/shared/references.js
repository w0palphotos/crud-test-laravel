import { usePage } from '@inertiajs/vue3';

/**
 * The reference lists, as the navigation draws them.
 *
 * Read from the shared props rather than declared here, so that adding a list to
 * App\Support\ReferenceRegistry is the only edit that puts it in the rail. A list
 * written into the sidebar and a list written into the registry are two places to
 * keep in step, and the one that falls behind renders as a link to a route that
 * does not exist — a nav entry that fails silently, because nothing at build time
 * knows the path is wrong.
 *
 * **A plain array, not a ref**, exactly like `useRoutes()` beside it. That is why
 * there is no `.value` below and why there must not be one above: `.value` on a
 * plain array is `undefined`, and `.map()` on that throws inside the computed that
 * builds the rail's groups — which is evaluated before the rail renders anything,
 * so it takes the entire shell down rather than one group of it. The two helpers
 * in this folder share one shape on purpose, so that reading either one tells you
 * how to use the other.
 *
 * The empty case is a real one: the prop is only meaningful on a page the shell
 * wraps, and an ordinary account is shown none of these lists.
 */
export function useReferences() {
    return usePage().props.referenceNav ?? [];
}