<script setup>
/**
 * The admin shell: a fixed rail, a topbar, and the page.
 *
 * Inertia keeps this component mounted across navigations, so the rail and the
 * topbar survive a page change instead of re-rendering — which is what lets the
 * drawer state and scroll position behave like a real application shell rather
 * than like a page that happens to have a menu.
 *
 * The signed-in user, the app name and the route map arrive as shared props from
 * HandleInertiaRequests rather than from any controller, which keeps the shell
 * and the pages it wraps from drifting apart.
 */
import { computed, ref } from "vue";
import { usePage } from "@inertiajs/vue3";

import AdminSidebar from "../components/AdminSidebar.vue";
import AdminTopbar from "../components/AdminTopbar.vue";
import { useRoutes } from "../shared/routes.js";

const page = usePage();
const routes = useRoutes();

const sidebarOpen = ref(false);

/**
 * Read once here and passed down, so the sidebar, the topbar and the assistant
 * agree on the account kind without each reaching into page props and guessing.
 */
const isAdmin = computed(() => Boolean(page.props.auth?.user?.is_admin));
const status = computed(() => page.props.status ?? null);
const appName = computed(() => page.props.appName);
</script>

<template>
    <div class="admin-shell bg-admin-canvas">
        <AdminSidebar
            :open="sidebarOpen"
            :is-admin="isAdmin"
            @close="sidebarOpen = false"
        />

        <div class="admin-main">
            <AdminTopbar
                :is-admin="isAdmin"
                @toggle-sidebar="sidebarOpen = !sidebarOpen"
            />

            <div class="admin-scroll">
                <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6">
                    <div
                        v-if="status"
                        role="status"
                        class="mb-6 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900"
                    >
                        {{ status }}
                    </div>

                    <slot />
                </main>
            </div>
        </div>
    </div>
</template>
