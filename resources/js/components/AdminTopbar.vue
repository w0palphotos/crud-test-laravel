<script setup>
/**
 * The dark bar across the top of the content column.
 *
 * Three things live here and nothing else: the drawer toggle, a quick-create
 * menu, and the link to the account.
 *
 * There were also a global search button and two icon-only controls for
 * notifications and messages. None of them had a query, a route or a click
 * handler behind them — the search button toggled a flag that no panel ever read,
 * and the other two were spans. They have been removed rather than disabled,
 * because a control that looks live and does nothing is worse than one that is
 * absent: it costs the reader a click to discover that the feature they were
 * shown does not exist.
 */
import { computed, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

import Icon from './Icon.vue';
import { useRoutes } from '../shared/routes.js';

const props = defineProps({
    /** Whether the signed-in account may use the sales desk. */
    isAdmin: { type: Boolean, default: false },
});

defineEmits(['toggle-sidebar']);

const page = usePage();
const routes = useRoutes();

const menuOpen = ref(false);

const initial = computed(() => (page.props.auth?.user?.name ?? '?').charAt(0).toUpperCase());

/**
 * Only routes that create something, and that exist.
 *
 * The menu used to offer Pelanggan and Penjualan, both of which opened the
 * listing pages rather than a form — so a control labelled "create" sent you to
 * read. Customer and supplier creation is not built, and inventing routes for it
 * here would have put links in the shell that 404.
 */
const CREATE_LINKS = [
    { label: 'Penjualan Baru', icon: 'receipt', route: 'salesCreate' },
    { label: 'Produk Baru', icon: 'box', route: 'productsCreate' },
];
</script>

<template>
    <header class="flex h-14 shrink-0 items-center gap-3 bg-admin-topbar px-4 text-white/80">
        <button
            type="button"
            class="rounded p-2 hover:bg-white/10 hover:text-white lg:hidden"
            aria-label="Buka menu"
            aria-controls="admin-sidebar"
            @click="$emit('toggle-sidebar')"
        >
            <Icon name="menu" class="size-5" />
        </button>

        <div v-if="isAdmin" class="relative" @keydown.esc="menuOpen = false">
            <button
                type="button"
                class="flex items-center gap-2 rounded px-3 py-1.5 text-sm hover:bg-white/10 hover:text-white"
                :aria-expanded="menuOpen"
                aria-haspopup="menu"
                @click="menuOpen = !menuOpen"
            >
                <Icon name="plus" class="size-4" />
                <span class="hidden sm:inline">Buat Baru</span>
                <Icon name="chevron-down" class="size-3.5" />
            </button>

            <ul
                v-if="menuOpen"
                class="absolute left-0 top-full z-50 mt-1 w-52 rounded bg-white py-1 text-sm text-neutral-800 shadow-lg"
            >
                <li v-for="item in CREATE_LINKS" :key="item.route">
                    <Link
                        :href="routes[item.route]"
                        class="flex items-center gap-2 px-4 py-2 hover:bg-neutral-100"
                        @click="menuOpen = false"
                    >
                        <Icon :name="item.icon" class="size-4 text-neutral-500" />
                        {{ item.label }}
                    </Link>
                </li>
            </ul>
        </div>

        <div class="flex-1" />

        <Link :href="routes.account" class="rounded px-2 py-1 hover:bg-white/10" title="Akun">
            <span
                class="grid size-7 place-items-center rounded-full bg-admin-warning text-xs font-semibold text-neutral-900"
            >
                {{ initial }}
            </span>
        </Link>
    </header>
</template>