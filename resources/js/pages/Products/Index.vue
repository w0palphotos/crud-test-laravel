<script setup>
/**
 * The product table.
 *
 * Rows arrive as a page prop rather than being fetched. The API runs under
 * auth:sanctum without EnsureFrontendRequestsAreStateful, so this page, which
 * authenticates with a session cookie, cannot call it — a fetch would answer 401.
 * Passing the page down also keeps ListProducts as the single place that decides
 * ordering, the name filter and the page-size cap.
 *
 * Sorting is client-side and covers the current page only. Changing the search or
 * the page is a server-side concern and goes back through Inertia.
 */
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';

import AdminLayout from '../../layouts/AdminLayout.vue';
import ConfirmDialog from '../../components/ConfirmDialog.vue';
import Icon from '../../components/Icon.vue';
import { formatRupiah } from '../../shared/rupiah.js';
import { useRoutes } from '../../shared/routes.js';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    products: { type: Object, required: true },
    search: { type: String, default: '' },
});

const routes = useRoutes();

const COLUMNS = [
    { key: 'name', label: 'Name', numeric: false },
    { key: 'price', label: 'Price', numeric: true },
    { key: 'stock', label: 'Stock', numeric: true },
];

const search = useForm({ q: props.search });

const sortKey = ref(null);
const sortDirection = ref('asc');

function submitSearch() {
    // preserveState keeps the table mounted, so the search box keeps focus and
    // the sort the reader chose survives the round trip.
    search.get(routes.products, { preserveState: true, replace: true });
}

function clearSearch() {
    search.q = '';
    search.get(routes.products, { preserveState: true, replace: true });
}

/**
 * Clicking the active column cycles ascending, descending, back to the order the
 * server sent. Null is a real state rather than a reset, so there is always a way
 * back to "newest first" without reloading.
 */
function sortBy(key) {
    if (sortKey.value === key) {
        if (sortDirection.value === 'asc') {
            sortDirection.value = 'desc';
        } else {
            sortKey.value = null;
            sortDirection.value = 'asc';
        }

        return;
    }

    sortKey.value = key;
    sortDirection.value = 'asc';
}

const sortedRows = computed(() => {
    if (!sortKey.value) {
        return props.products.data;
    }

    const key = sortKey.value;
    const factor = sortDirection.value === 'asc' ? 1 : -1;

    // Copied before sorting: props are readonly and sort() mutates in place.
    return [...props.products.data].sort((left, right) => {
        if (key === 'name') {
            return left.name.localeCompare(right.name) * factor;
        }

        return (left[key] - right[key]) * factor;
    });
});

function ariaSort(column) {
    if (sortKey.value !== column.key) {
        return 'none';
    }

    return sortDirection.value === 'asc' ? 'ascending' : 'descending';
}

function indicator(column) {
    if (sortKey.value !== column.key) {
        return '';
    }

    return sortDirection.value === 'asc' ? '↑' : '↓';
}

/**
 * Page links only, taken from the paginator's own link list.
 *
 * The list also holds Laravel's `&laquo; Previous` / `Next &raquo;` entries,
 * whose labels are HTML entities. Filtering to the ones where the label is the
 * page number leaves two safe strings to render instead of needing v-html on a
 * value the server happened to generate. The arrows come from prev_page_url and
 * next_page_url instead.
 */
const pageLinks = computed(() =>
    props.products.links.filter((link) => link.label === String(link.page)),
);
</script>

<template>
    <Head title="Produk" />

    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-lg font-semibold tracking-tight">Produk</h1>
            :href="routes.productsCreate"
            class="rounded-md bg-admin-primary px-3 py-2 text-sm font-medium text-white hover:bg-admin-primary-dark"
        >
            Produk Baru
        </Link>
    </div>

    <form class="mt-6 flex gap-2" role="search" @submit.prevent="submitSearch">
        <label for="q" class="sr-only">Cari berdasarkan nama</label>
        <input
            id="q"
            v-model="search.q"
            type="search"
            placeholder="Cari berdasarkan nama"
            class="w-full max-w-xs rounded-md border border-neutral-300 px-3 py-2 text-sm"
        >
        <button
            type="submit"
            class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium hover:bg-neutral-100"
        >
            Search
        </button>
        <button
            v-if="search.q !== ''"
            type="button"
            class="px-2 py-2 text-sm text-neutral-600 hover:underline"
            @click="clearSearch"
        >
            Clear
        </button>
    </form>

    <p
        v-if="products.data.length === 0"
        class="mt-8 rounded border border-dashed border-neutral-300 bg-admin-surface px-4 py-10 text-center text-sm text-neutral-600"
    >
        {{ search.q === '' ? 'No products yet.' : `No products match "${search.q}".` }}
    </p>

    <template v-else>
        <div class="mt-6 overflow-hidden rounded bg-admin-surface shadow-admin-card">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">Produk</caption>

                <thead class="border-b border-neutral-200 bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500">
                    <tr>
                        <th
                            v-for="column in COLUMNS"
                            :key="column.key"
                            scope="col"
                            class="px-4 py-3 font-medium"
                            :class="column.numeric ? 'text-right' : ''"
                            :aria-sort="ariaSort(column)"
                        >
                            <button
                                type="button"
                                class="inline-flex items-center gap-1 hover:text-neutral-900"
                                @click="sortBy(column.key)"
                            >
                                <span>{{ column.label }}</span>
                                <span aria-hidden="true">{{ indicator(column) }}</span>
                            </button>
                        </th>

                    </tr>
                </thead>

                <tbody class="divide-y divide-neutral-100">
                    <tr v-for="product in sortedRows" :key="product.id">
                        <!--
                            The thumbnail goes inside the row header rather than in a
                            column of its own. A separate column would have to be
                            declared, and every row without a picture would leave it
                            holding a placeholder — a column of placeholders reads as
                            broken thumbnails rather than as products that have none.
                        -->
                        <th scope="row" class="px-4 py-3 font-medium">
                            <div class="flex items-start gap-3">
                                <img
                                    v-if="product.thumbnail_url"
                                    :src="product.thumbnail_url"
                                    :alt="product.name"
                                    class="size-10 shrink-0 rounded object-cover"
                                    loading="lazy"
                                >
                                <span
                                    v-else
                                    aria-hidden="true"
                                    class="grid size-10 shrink-0 place-items-center rounded bg-neutral-100 text-neutral-300"
                                >
                                    <Icon name="box" class="size-5" />
                                </span>

                                <span class="min-w-0">
                                    {{ product.name }}
                                    <span
                                        v-if="product.description"
                                        class="mt-0.5 block text-xs font-normal text-neutral-500 line-clamp-2"
                                    >
                                        {{ product.description }}
                                    </span>
                                </span>
                            </div>
                        </th>

                        <td class="px-4 py-3 text-right tabular-nums">Rp {{ formatRupiah(product.price) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ product.stock }}</td>

                        
                            <div class="flex items-center justify-end gap-1">
                                <Link
                                    :href="product.edit_url"
                                    class="rounded-md px-2 py-1 text-sm font-medium text-neutral-700 hover:bg-neutral-100"
                                >
                                    Edit
                                </Link>

                                <ConfirmDialog
                                    :action="product.destroy_url"
                                    prefix="Delete "
                                    :label="product.name"
                                    question="? This cannot be undone."
                                    trigger-label="Delete"
                                    confirm-label="Delete"
                                    trigger-class="rounded-md px-2 py-1 text-sm font-medium text-admin-danger hover:bg-orange-50"
                                />
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav v-if="products.last_page > 1" class="mt-4 flex items-center justify-between gap-2 text-sm">
            <Link
                v-if="products.prev_page_url"
                :href="products.prev_page_url"
                preserve-scroll
                class="rounded-md border border-neutral-300 px-3 py-1.5 font-medium hover:bg-neutral-100"
            >
                Previous
            </Link>
            <span v-else></span>

            <div class="flex items-center gap-1">
                <Link
                    v-for="link in pageLinks"
                    :key="link.page"
                    :href="link.url"
                    preserve-scroll
                    class="rounded-md px-3 py-1.5 font-medium"
                    :class="link.active ? 'bg-admin-primary text-white' : 'border border-neutral-300 hover:bg-neutral-100'"
                >
                    {{ link.label }}
                </Link>
            </div>

            <Link
                v-if="products.next_page_url"
                :href="products.next_page_url"
                preserve-scroll
                class="rounded-md border border-neutral-300 px-3 py-1.5 font-medium hover:bg-neutral-100"
            >
                Next
            </Link>
            <span v-else></span>
        </nav>
    </template>
</template>