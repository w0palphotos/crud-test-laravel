<script setup>
/**
 * The sales ledger.
 *
 * The filters and the page are server state: router.get() replaces the page
 * component with fresh props, so the query string is the single description of
 * what is on screen and a filtered list can be linked to or reloaded.
 *
 * Ordering, the status filter and the page-size cap come from ListSales, the
 * same action the API's own listing would use.
 */
import { computed, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';

import AdminLayout from '../../layouts/AdminLayout.vue';
import Icon from '../../components/Icon.vue';
import PanelSection from '../../components/PanelSection.vue';
import { formatRupiah } from '../../shared/rupiah.js';
import { useRoutes } from '../../shared/routes.js';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    sales: { type: Object, required: true },
    filters: { type: Object, required: true },
    /** The status filter's options, built from SaleStatus on the server. */
    statuses: { type: Array, required: true },
});

const routes = useRoutes();

const STATUS_TONE = {
    draft: 'bg-neutral-400',
    pending: 'bg-admin-warning',
    confirmed: 'bg-admin-primary',
    shipped: 'bg-admin-cyan',
    delivered: 'bg-admin-success',
    cancelled: 'bg-admin-danger',
};

const search = useForm({ q: props.filters.search, status: props.filters.status ?? '' });

/**
 * Re-read when the server sends a different query back, which happens when the
 * filter is cleared from the URL or a navigation is restored from history.
 */
watch(() => props.filters, (filters) => {
    search.q = filters.search;
    search.status = filters.status ?? '';
}, { deep: true });

function applyFilters() {
    // preserveState keeps the toolbar mounted so focus survives the round trip.
    search.get(routes.sales, { preserveState: true, replace: true });
}

function clearFilters() {
    search.q = '';
    search.status = '';
    applyFilters();
}

const pageLinks = computed(() => props.sales.links.filter((link) => link.label === String(link.page)));
</script>

<template>
    <Head title="Penjualan" />

    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-medium text-neutral-800">Penjualan</h1>
            <p class="mt-1 text-sm text-neutral-500">
                {{ sales.total }} transaksi tercatat.
            </p>
        </div>
    </div>

    <PanelSection title="Ledger Penjualan" subtitle="Filter dan halaman">
        <template #action>
            <Link
                :href="routes.salesCreate"
                class="rounded bg-admin-primary px-3 py-2 text-xs font-medium text-white hover:bg-admin-primary-dark"
            >
                Penjualan Baru
            </Link>
        </template>

        <form class="mb-4 flex flex-wrap gap-2" role="search" @submit.prevent="applyFilters">
            <label for="q" class="sr-only">Cari</label>
            <input
                id="q"
                v-model="search.q"
                type="search"
                placeholder="Referensi, pelanggan, atau sales"
                class="w-full max-w-xs rounded border border-neutral-300 px-3 py-2 text-sm focus:border-admin-primary focus:outline-none"
            >

            <label for="status" class="sr-only">Status</label>
            <select
                id="status"
                v-model="search.status"
                class="rounded border border-neutral-300 px-3 py-2 text-sm focus:border-admin-primary focus:outline-none"
            >
                <option v-for="status in statuses" :key="status.value" :value="status.value">
                    {{ status.label }}
                </option>
            </select>

            <button
                type="submit"
                class="rounded bg-admin-primary px-3 py-2 text-sm font-medium text-white hover:bg-admin-primary-dark"
            >
                Terapkan
            </button>

            <button
                v-if="search.q !== '' || search.status !== ''"
                type="button"
                class="px-2 py-2 text-sm text-neutral-500 hover:underline"
                @click="clearFilters"
            >
                Reset
            </button>
        </form>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[46rem] text-left text-sm">
                <thead class="border-b border-neutral-200 text-xs uppercase tracking-wide text-neutral-500">
                    <tr>
                        <th scope="col" class="py-2 font-medium">Referensi</th>
                        <th scope="col" class="py-2 font-medium">Pelanggan</th>
                        <th scope="col" class="py-2 font-medium">Sales</th>
                        <th scope="col" class="py-2 font-medium">Kota</th>
                        <th scope="col" class="py-2 font-medium">Status</th>
                        <th scope="col" class="py-2 text-right font-medium">Total</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-neutral-100">
                    <tr v-for="sale in sales.data" :key="sale.id">
                        <td class="py-2.5 font-medium text-neutral-800">
                            <Link :href="sale.url" class="hover:text-admin-primary hover:underline">
                                {{ sale.reference }}
                            </Link>
                            <span class="block text-xs font-normal text-neutral-500">{{ sale.soldAt }}</span>
                        </td>
                        <td class="py-2.5 text-neutral-600">{{ sale.customer }}</td>
                        <td class="py-2.5 text-neutral-600">{{ sale.salesperson }}</td>
                        <td class="py-2.5 text-neutral-600">{{ sale.city }}</td>
                        <td class="py-2.5">
                            <span
                                class="rounded-full px-2 py-0.5 text-xs font-medium text-white"
                                :class="STATUS_TONE[sale.status] ?? 'bg-neutral-400'"
                            >
                                {{ sale.statusLabel }}
                            </span>
                        </td>
                        <td class="py-2.5 text-right tabular-nums text-neutral-800">
                            Rp {{ formatRupiah(sale.grandTotal) }}
                        </td>
                    </tr>

                    <tr v-if="sales.data.length === 0">
                        <td colspan="6" class="py-10 text-center text-sm text-neutral-500">
                            Tidak ada penjualan yang cocok.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav v-if="sales.last_page > 1" class="mt-4 flex items-center justify-between text-sm">
            <Link
                v-if="sales.prev_page_url"
                :href="sales.prev_page_url"
                preserve-scroll
                class="rounded border border-neutral-300 px-3 py-1.5 hover:bg-neutral-100"
            >
                Sebelumnya
            </Link>
            <span v-else></span>

            <div class="flex items-center gap-1">
                <Link
                    v-for="link in pageLinks"
                    :key="link.page"
                    :href="link.url"
                    preserve-scroll
                    class="rounded px-3 py-1.5"
                    :class="link.active ? 'bg-admin-primary text-white' : 'border border-neutral-300 hover:bg-neutral-100'"
                >
                    {{ link.label }}
                </Link>
            </div>

            <Link
                v-if="sales.next_page_url"
                :href="sales.next_page_url"
                preserve-scroll
                class="rounded border border-neutral-300 px-3 py-1.5 hover:bg-neutral-100"
            >
                Berikutnya
            </Link>
            <span v-else></span>
        </nav>
    </PanelSection>
</template>