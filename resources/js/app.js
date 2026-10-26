import { createInertiaApp } from '@inertiajs/vue3';
import { createApp, h } from 'vue';
import { router } from '@inertiajs/vue3';
import { usePageCache } from './shared/pageCache';

const { save, get, invalidate } = usePageCache();

/**
 * Set while a client-side page swap from the cache is in flight, so the
 * `before` event handler triggered by `router.replace` doesn't try to read
 * the cache again and loop forever.
 */
let skipCache = false;

/**
 * Before every Inertia navigation: if we already have the target page cached,
 * skip the server entirely and swap the page from sessionStorage.
 *
 * Only GET navigations with no form data are eligible — form submissions,
 * POST/PATCH/DELETE, and client-side visits we initiate ourselves always
 * proceed to the server.
 */
router.on('before', (visit) => {
    if (skipCache) {
        skipCache = false;
        return;
    }

    if (visit.method !== 'get' || visit.data) {
        return;
    }

    const url = visit.url.toString();
    const cached = get(url);
    if (!cached) {
        return;
    }

    // We have the page in sessionStorage: cancel the server round-trip and
    // swap the page client-side. `router.replace` updates the URL and replaces
    // the page props without a request, which is what makes the navigation
    // instant.
    skipCache = true;
    router.replace({
        component: cached.component,
        url: cached.url,
        props: cached.props,
    });
    return false;
});

// Cache every successful page visit so the next one can be served from
// sessionStorage. The TTL in pageCache keeps entries from going stale.
router.on('success', (page) => {
    save(page.url, page);
});

createInertiaApp({
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
});
