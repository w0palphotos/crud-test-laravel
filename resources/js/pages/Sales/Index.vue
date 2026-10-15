<script setup>
/**
 * The sales ledger.
 *
 * The filters and the page are server state: router.get() replaces the page
 * component with fresh props, so the query string is the single description of
 * what is on screen and a filtered list can be linked to or reloaded.
 *
 * Ordering, the status filter, the tag filter, the date range and the page-size cap
 * come from ListSales, the same action the API's own listing would use.
 */
import { computed, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';

import AdminLayout from '../../layouts/AdminLayout.vue';
import DateRangePicker from '../../components/DateRangePicker.vue';
import Icon from '../../components/Icon.vue';
import MultiSelect from '../../components/MultiSelect.vue';
import PanelSection from '../../components/PanelSection.vue';
import { formatRupiah } from '../../shared/rupiah.js';
import { useRoutes } from '../../shared/routes.js';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    sales: { type: Object, required: true },
    filters: { type: Object, required: true },
    /** The status filter's options, built from SaleStatus on the server. */
    statuses: { type: Array, required: true },
    /** The tag filter's options, read from the tags table on the server. */
    tags: { type: Array, required: true },
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

const search = useForm({
    q: props.filters.search,
    status: props.filters.status ?? '',
    // An array, because the query parameter is tag[]=1&tag[]=3 and the server
    // reads it as a list. A scalar here would silently drop the second tag.
    tag: props.filters.tags ?? [],
    // Both ends of the period, either of which may be empty: a range open at one
    // side is the ordinary way to ask "since the start of the month" or "up to
    // today".
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});

const hasFilters = computed(
    () => search.q !== '' || search.status !== '' || search.tag.length > 0
        || search.from !== '' || search.to !== '',
);

/**
 * Re-read when the server sends a different query back, which happens when the
 * filter is cleared from the URL or a navigation is restored from history.
 *
 * The range is read back though it is also written, because the server may hand
 * back a different pair than the one sent: an inverted range comes back ordered, so
 * the two inputs correcting themselves is how the person sees that the dates they
 * typed were read the other way round rather than as an empty month.
 */
watch(() => props.filters, (filters) => {
    search.q = filters.search;
    search.status = filters.status ?? '';
    search.tag = filters.tags ?? [];
    search.from = filters.from ?? '';
    search.to = filters.to ?? '';
}, { deep: true });

function applyFilters() {
    // preserveState keeps the toolbar mounted so focus survives the round trip.
    search.get(routes.sales, { preserveState: true, replace: true });
}

function clearFilters() {
    search.q = '';
    search.status = '';
    search.tag = [];
    search.from = '';
    search.to = '';
    applyFilters();
}

/**
 * The range handed to the picker, in the object its v-model expects.
 *
 * A computed rather than an object literal in the template, so the pair keeps its
 * identity between renders. The picker reads its own value to build the calendar
 * grid, and a fresh object on every render would have it rebuilding the month for
 * reasons that have nothing to do with the range.
 *
 * Read-only, and the picker is told so explicitly rather than through a v-model:
 * the page is the only thing that writes the range, and a listener named in
 * camelCase is the spelling `v-model` itself compiles to.
 */
const range = computed(() => ({
    start: search.from || null,
    end: search.to || null,
}));

function onRangeChange(value) {
    search.from = value.start ?? '';
    search.to = value.end ?? '';
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
            <div class="flex flex-wrap gap-2">
                <!--
                    Secondary to Penjualan Baru, not beside it as an equal: one row
                    at a time is the ordinary way to file a sale and the button is
                    already the loud one on the page. Bulk entry is the exception,
                    and an exception that has to compete for the same visual weight
                    as the daily path is a page that pushes people back to the form.
                -->
                <Link
                    :href="routes.salesImport"
                    class="rounded border border-neutral-300 px-3 py-2 text-xs font-medium hover:bg-neutral-100"
                >
                    Impor
                </Link>

                <Link
                    :href="routes.salesCreate"
                    class="rounded bg-admin-primary px-3 py-2 text-xs font-medium text-white hover:bg-admin-primary-dark"
                >
                    Penjualan Baru
                </Link>
            </div>
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

            <!--
                A pillbox rather than a checkbox row: the chosen tags stay
                visible with their own delete buttons, which is what makes
                unticking one of several a single gesture. Sized to the search box
                beside it so the three reads as one toolbar row, and wrapping to
                its own line on a narrow screen rather than being squeezed.
            -->
            <fieldset v-if="tags.length" class="w-full max-w-xs">
                <legend class="sr-only">Tag</legend>

                <MultiSelect
                    v-model="search.tag"
                    :options="tags"
                    input-id="sale-tag-filter"
                    placeholder="Cari atau pilih tag..."
                />
            </fieldset>

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

            <!--
                The period: one control that opens a calendar, rather than two date
                inputs with a dash between them. Two boxes read as two filters and
                left the person comparing them to work out which range was in force;
                here the span is drawn in the calendar and named on the button,
                including when it is open at one side.
            -->
            <label for="sale-date-range" class="sr-only">Rentang tanggal</label>
            <DateRangePicker
                :model-value="range"
                trigger-id="sale-date-range"
                @update:modelValue="onRangeChange"
            />

            <button
                type="submit"
                class="rounded bg-admin-primary px-3 py-2 text-sm font-medium text-white hover:bg-admin-primary-dark"
            >
                Terapkan
            </button>

            <button
                v-if="hasFilters"
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
                        <th scope="col" class="py-2 font-medium">Tag</th>
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
                        <td class="py-2.5">
                            <!--
                                The same chip the filter's pills wear, so a tag
                                reads identically wherever it appears. An empty
                                cell says nothing rather than a dash: most sales
                                are untagged, and a placeholder on every one of
                                them would be noise pretending to be information.
                            -->
                            <span class="flex flex-wrap gap-1">
                                <span
                                    v-for="tag in sale.tags"
                                    :key="tag.id"
                                    class="rounded-full px-2 py-0.5 text-xs font-medium text-neutral-800"
                                    :style="{ backgroundColor: tag.colour }"
                                >
                                    {{ tag.name }}
                                </span>
                            </span>
                        </td>
                        <td class="py-2.5 text-right tabular-nums text-neutral-800">
                            Rp {{ formatRupiah(sale.grandTotal) }}
                        </td>
                    </tr>

                    <tr v-if="sales.data.length === 0">
                        <td colspan="7" class="py-10 text-center text-sm text-neutral-500">
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