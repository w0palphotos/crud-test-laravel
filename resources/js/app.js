import { createInertiaApp } from '@inertiajs/vue3';
import { createApp, h } from 'vue';
import { router } from '@inertiajs/vue3';
import { usePageCache } from './shared/pageCache';

const { save, get, invalidate } = usePageCache();

/**
 * Set while a client-side page swap from the cache is in flight, so the
 * `beforeEach` hook triggered by `router.replace` doesn't try to read the
 * cache again and loop forever.
 */
let skipCache = false;

/**
 * Before every Inertia navigation: if we already have the target page cached,
 * skip the server entirely and swap the page from sessionStorage.
 *
 * Only GET navigations with no form data and the default visit type are
 * eligible — form submissions, POST/PATCH/DELETE, and client-side visits we
 * initiate ourselves always go straight to `proceed()`.
 */
router.beforeEach((to, from, proceed, cancel) => {
    if (skipCache) {
        skipCache = false;
        proceed();
        return;
    }

    if (to.method !== 'get' || to.data || to.visit !== 'default') {
        proceed();
        return;
    }

    const cached = get(to.url);
    if (!cached) {
        proceed();
        return;
    }

    // We have the page in sessionStorage: cancel the server round-trip and
    // swap the page client-side. `router.replace` updates the URL and replaces
    // the page props without a request, which is what makes the navigation
    // instant.
    cancel();
    skipCache = true;
    router.replace({
        component: cached.component,
        url: cached.url,
        props: cached.props,
    });
});

// Cache every successful page visit so the next one can be served from
// sessionStorage. The TTL in pageCache keeps entries from going stale.
router.onSuccess((page) => {
    save(page.url, page);
});

createInertiaApp({
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
});
