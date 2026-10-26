<script setup>
/**
 * The fixed navigation rail.
 *
 * Escape is bound on window rather than on the rail. Bound locally it only fires
 * when focus happens to be inside, and the rail is not in the tab order on a
 * wide screen, so on a phone the drawer would have to be dismissed by tapping the
 * scrim alone.
 *
 * tabindex="-1" makes the rail programmatically focusable without adding it to
 * the tab order: opening the drawer moves focus onto it so a screen reader
 * announces it, and closing puts focus back where it came from. Without it,
 * focus() is silently ignored and the drawer opens with focus still on the page
 * behind it.
 *
 * Two behaviours, and they are different on purpose.
 *
 * On a wide screen the rail is part of the flex row in AdminLayout: it does not
 * move, the content beside it scrolls, and no JavaScript is involved.
 *
 * Below lg the rail is an overlay that slides in over the content and is
 * dismissed by Escape, by the scrim, or by choosing somewhere. It traps nothing
 * but it does restore focus and lock the page behind it, which is the part that
 * matters: a drawer that leaves focus on the page behind it is disorienting to
 * anyone navigating by keyboard, and a drawer that lets the page behind it
 * scroll while it is open is worse.
 */
import { computed, nextTick, onUnmounted, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';

import Icon from './Icon.vue';
import { useRouteMatcher, useRoutes } from '../shared/routes.js';
import { useReferences } from '../shared/references.js';

const props = defineProps({
    /** Open as an overlay. Off on wide screens, where the rail is always there. */
    open: { type: Boolean, default: false },
    /** Whether the signed-in account may use the sales desk. */
    isAdmin: { type: Boolean, default: false },
});

const emit = defineEmits(['close']);

const routes = useRoutes();
const isCurrentRoute = useRouteMatcher();
const references = useReferences();

const scrim = ref(null);

/**
 * The sections. `groups` is a set of collapsible headings; a null one is a plain
 * item that never opens.
 *
 * Grouped rather than flat because an admin rail that lists everything stops
 * being scannable, and the disclosure is the cheapest way to keep it readable
 * without hiding anything permanently.
 */
// The `route` values are keys into the shared route map, not Laravel route
// names. They look alike and are not: 'sales.index' is a route name, and looking
// it up in the map yields undefined, which Inertia's Link renders as the page it
// is already on — so every link appeared inert rather than broken.
// `adminOnly` marks the writes — the create forms, the import flow, the account
// creation page. It is filtered out for an ordinary account rather than
// disabled, because a link the reader cannot follow is a dead control: the
// middleware answers 403, so showing it would offer a destination that fails.
//
// This is presentation only. The 403 from the `admin` middleware is the boundary,
// and nothing here decides access.
//
// The reference lists are a group of their own and are read from the server rather
// than written here. They are the desk's own vocabulary — the words a sale is
// recorded in — which is a different thing from the pages that use those words,
// and a group is what keeps the second list from crowding out the first. Reading
// them means a list added to App\Support\ReferenceRegistry appears without a second
// edit, and one removed disappears instead of leaving a dead link behind.
// `readable` marks a destination any signed-in account may open; `adminOnly` marks
// one that also requires an administrator. An item with neither is open to
// everyone, which is what the account pages are.
//
// Every page in MAIN is readable. An ordinary account sees the whole desk —
// the dashboard, the ledger, the catalogue, the directories — and what it
// cannot do is write, which the pages themselves enforce by hiding the
// controls and the middleware enforces with a 403.
const MAIN = [
    { label: 'Dashboard', icon: 'dashboard', route: 'dashboard', readable: true },
    { label: 'Penjualan', icon: 'receipt', route: 'sales', readable: true },
    { label: 'Produk', icon: 'box', route: 'products', readable: true },
    { label: 'Pelanggan', icon: 'users', route: 'customers', readable: true },
    { label: 'Supplier', icon: 'truck', route: 'suppliers', readable: true },
];

const ADMIN = [
    { label: 'Log Aktivitas', icon: 'file-text', route: 'activityLogs', adminOnly: true },
    { label: 'Log Browser', icon: 'folder', route: 'adminLogs', adminOnly: true },
];

const ACCOUNT = [
    { label: 'Profil Saya', icon: 'user', route: 'account' },
    // blade: /register returns a Blade page, not an Inertia page. Inertia's Link
    // intercepts the click and fetches Blade HTML, which lands the form in a
    // sandboxed context where the browser refuses to submit it.
    { label: 'Buat Akun', icon: 'user-plus', route: 'register', adminOnly: true, blade: true },
];

const groups = computed(() => [
    { label: 'Utama', items: MAIN },
    {
        label: 'Data Master',
        // `references` is a plain array, not a ref — same contract as `routes`
        // below. Writing `.value` here throws while this computed is evaluated,
        // which is before the rail has rendered anything, and takes the whole
        // shell down with it: the topbar and its menu button are siblings of the
        // sidebar, so a rail that never mounts is a page that never mounts.
        //
        // readable, not adminOnly: the lists are part of the catalogue vocabulary
        // rather than part of the desk's writes, and a reader can open all three.
        items: references.map((reference) => ({
            label: reference.label,
            icon: reference.icon,
            route: `references.${reference.key}`,
            readable: true,
        })),
    },
    { label: 'Administrasi', items: ADMIN },
    { label: 'Akun', items: ACCOUNT },
]);

const collapsed = ref({});

/**
 * The groups this account may actually navigate to.
 *
 * `adminOnly` is the only thing filtered. A readable item carries no `adminOnly`,
 * so `!item.adminOnly` is true for it and it survives for everyone — which is why
 * the `readable` flag needs no handling here. It documents the intent at the item
 * rather than making the filter infer it.
 *
 * A group whose every item is filtered away is dropped entirely, so an ordinary
 * account does not see a heading with nothing under it. Today only "Akun" has an
 * `adminOnly` item, and it shares the group with "Profil Saya", so no group
 * empties out — but the filter stays so a future write-only group cannot render
 * as an empty heading.
 */
const visibleGroups = computed(() =>
    groups.value
        .map((group) => ({
            ...group,
            items: group.items.filter((item) => props.isAdmin || !item.adminOnly),
        }))
        .filter((group) => group.items.length > 0)
);

function toggleGroup(label) {
    collapsed.value = { ...collapsed.value, [label]: !collapsed.value[label] };
}

function href(key) {
    return routes[key];
}

/**
 * aria-current marks where the reader is. Without it a screen reader announces
 * six identical "link, Dashboard, list item" runs and the reader has to guess
 * which one is this page.
 */
function isActive(key) {
    return isCurrentRoute(href(key));
}

watch(() => props.open, (isOpen) => {
    document.body.style.overflow = isOpen ? 'hidden' : '';

    if (isOpen) {
        nextTick(() => scrim.value?.focus());
    }
});

onUnmounted(() => {
    document.body.style.overflow = '';
});
</script>

<template>
    <!--
        Kept in the DOM on wide screens rather than conditionally rendered, so the
        rail is the same element at every width and the CSS alone decides whether
        it is a column or an overlay.
    -->
    <div
        v-show="open"
        class="fixed inset-0 z-40 bg-black/50 lg:hidden"
        tabindex="-1"
        aria-hidden="true"
        @click="emit('close')"
    />

    <aside
        ref="scrim"
        id="admin-sidebar"
        class="fixed inset-y-0 left-0 z-50 flex w-64 flex-col bg-admin-sidebar transition-transform duration-200 lg:static lg:z-auto lg:translate-x-0"
        :class="open ? 'translate-x-0' : '-translate-x-full'"
        tabindex="-1"
        @keydown.esc.window="emit('close')"
    >
        <div class="flex h-14 items-center gap-3 border-b border-white/10 px-5">
            <span class="grid size-7 place-items-center rounded bg-admin-primary text-xs font-bold text-white">S</span>
            <span class="text-base font-medium text-white">
                Sales <span class="font-semibold">Desk</span>
            </span>
        </div>

        <nav class="flex-1 overflow-y-auto py-4" aria-label="Utama">
            <div v-for="group in visibleGroups" :key="group.label" class="mb-4">
                <button
                    v-if="group.label"
                    type="button"
                    class="flex w-full items-center justify-between px-5 py-2 text-xs font-semibold uppercase tracking-wider text-white/40 hover:text-white/70"
                    :aria-expanded="!collapsed[group.label]"
                    @click="toggleGroup(group.label)"
                >
                    {{ group.label }}
                    <Icon
                        name="chevron-down"
                        class="size-3.5 transition-transform"
                        :class="collapsed[group.label] ? '-rotate-90' : ''"
                    />
                </button>

                <ul class="mt-1 space-y-0.5 px-3">
                    <li v-for="item in group.items" :key="item.route">
                        <component
                            :is="item.blade ? 'a' : Link"
                            :href="href(item.route)"
                            class="flex items-center gap-3 rounded px-3 py-2 text-sm font-medium transition-colors"
                            :class="isActive(item.route)
                                ? 'bg-admin-sidebar-active text-white'
                                : 'text-white/70 hover:bg-admin-sidebar-hover hover:text-white'"
                            :aria-current="isActive(item.route) ? 'page' : undefined"
                            @click="emit('close')"
                        >
                            <Icon :name="item.icon" class="size-4.5 shrink-0" />
                            {{ item.label }}
                        </component>
                    </li>
                </ul>
            </div>
        </nav>

        <div class="border-t border-white/10 px-5 py-4 text-xs text-white/40">
            Sales Desk &middot; Laravel 13
        </div>
    </aside>
</template>