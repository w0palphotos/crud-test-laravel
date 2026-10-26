/**
 * Client-side page cache for Inertia navigation.
 *
 * Stores full Inertia page responses in sessionStorage so revisiting a page
 * is instant — no server round-trip at all. Cache is invalidated when data
 * changes (create/edit/delete) so readers never see a stale page.
 *
 * Each entry is namespaced as `page-cache:<url>` and carries a timestamp so
 * entries expire after a short TTL (60s, matching the server-side
 * Cache-Control max_age from CacheInertiaResponses).
 */

const TTL_MS = 60_000;
const PREFIX = 'page-cache:';

function key(url) {
    return `${PREFIX}${url}`;
}

export function usePageCache() {
    /**
     * Save an Inertia page response to sessionStorage.
     *
     * @param {string} url - Full page URL (including query string).
     * @param {object} page - Inertia page object (url, component, props, version).
     */
    function save(url, page) {
        try {
            sessionStorage.setItem(key(url), JSON.stringify({
                page,
                savedAt: Date.now(),
            }));
        } catch (_) {
            // sessionStorage may be unavailable or quota-exceeded (e.g. private
            // browsing mode). Fail silently: the app still works without cache.
        }
    }

    /**
     * Get a cached page for the given URL, or null if none / expired.
     *
     * @param {string} url
     * @returns {object|null}
     */
    function get(url) {
        try {
            const raw = sessionStorage.getItem(key(url));
            if (!raw) return null;

            const { page, savedAt } = JSON.parse(raw);
            if (Date.now() - savedAt > TTL_MS) {
                sessionStorage.removeItem(key(url));
                return null;
            }

            return page;
        } catch (_) {
            return null;
        }
    }

    /**
     * Remove a single URL from the cache.
     */
    function invalidate(url) {
        try {
            sessionStorage.removeItem(key(url));
        } catch (_) {
            // ignore
        }
    }

    /**
     * Remove every cached page whose URL starts with the given prefix.
     */
    function invalidatePrefix(prefix) {
        try {
            for (let i = 0; i < sessionStorage.length; i++) {
                const k = sessionStorage.key(i);
                if (k && k.startsWith(PREFIX) && k.slice(PREFIX.length).startsWith(prefix)) {
                    sessionStorage.removeItem(k);
                    i--;
                }
            }
        } catch (_) {
            // ignore
        }
    }

    /**
     * Clear the entire cache.
     */
    function clear() {
        try {
            for (let i = 0; i < sessionStorage.length; i++) {
                const k = sessionStorage.key(i);
                if (k && k.startsWith(PREFIX)) {
                    sessionStorage.removeItem(k);
                    i--;
                }
            }
        } catch (_) {
            // ignore
        }
    }

    return { save, get, invalidate, invalidatePrefix, clear };
}
