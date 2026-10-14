import { createInertiaApp } from '@inertiajs/vue3';
import { createApp, h } from 'vue';

/**
 * The frontend entry point.
 *
 * `createApp` rather than `createSSRApp`: this is Inertia's client-rendered mode,
 * which is what lets the app run on a runtime image with no Node in it. Inertia's
 * own SSR adapter needs a Node process answering on every request, and
 * Dockerfile.vercel builds assets in a Node stage and then ships PHP only.
 * config/inertia.php turns the package's SSR default off for the same reason.
 *
 * The `resolve` option is not written here. @inertiajs/vite injects it at build
 * time, globbing ./pages for .vue files, so the page list exists in exactly one
 * place.
 */
createInertiaApp({
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
});