<script setup>
/**
 * The sales desk dashboard.
 *
 * Everything here is read-only. A dashboard that could change the ledger would be
 * a second place to put the rules, and SalesSummary already exists so the numbers
 * on this page are the same ones a list you click through from would show.
 */
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';

import AdminLayout from '../../layouts/AdminLayout.vue';
import Icon from '../../components/Icon.vue';
import PanelSection from '../../components/PanelSection.vue';
import RevenueChart from '../../components/RevenueChart.vue';
import { formatCompact, formatRupiah } from '../../shared/rupiah.js';
import { useRoutes } from '../../shared/routes.js';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    headline: { type: Object, required: true },
    monthly: { type: Array, required: true },
    topCustomers: { type: Array, required: true },
    slowMovers: { type: Array, required: true },
    recentSales: { type: Array, required: true },
    lowStock: { type: Array, required: true },
    statusCounts: { type: Object, required: true },
});

const routes = useRoutes();

/**
 * The coloured tiles.
 *
 * These are the desk's own sections, so the tile grid and the sidebar say the
 * same thing in two places. That redundancy is the point rather than a
 * duplication bug: the rail is a narrow vertical list for scanning and the tiles
 * are the large targets someone lands on, and a dashboard that only showed the
 * rail would make the reader hunt for anything they had not already found.
 */
const TILES = [
    { label: 'Dashboard', icon: 'dashboard', route: 'dashboard', tone: 'bg-admin-primary' },
    { label: 'Penjualan', icon: 'receipt', route: 'sales', tone: 'bg-admin-success' },
    { label: 'Produk', icon: 'box', route: 'products', tone: 'bg-admin-warning' },
    { label: 'Pelanggan', icon: 'users', route: 'customers', tone: 'bg-admin-danger' },
    { label: 'Supplier', icon: 'truck', route: 'suppliers', tone: 'bg-admin-navy' },
];

/**
 * The dark statistic tiles beside the chart.
 *
 * The month-on-month figure is shown as a direction rather than as a second
 * number, because a bare total cannot tell a reader whether the business is
 * doing better — only the change can.
 */
const stats = computed(() => {
    const now = Number(props.headline.revenueThisMonth);
    const before = Number(props.headline.revenueLastMonth);
    const delta = before === 0 ? null : ((now - before) / before) * 100;

    return [
        {
            icon: 'wallet',
            label: 'Pendapatan Bulan Ini',
            value: `Rp ${formatCompact(now)}`,
            detail: delta === null
                ? 'Bulan lalu belum ada'
                : `${delta >= 0 ? '+' : ''}${delta.toFixed(1)}% dari bulan lalu`,
            tone: delta === null || delta >= 0 ? 'text-emerald-400' : 'text-rose-400',
        },
        {
            icon: 'receipt',
            label: 'Penjualan Terbuka',
            value: props.headline.openSales,
            detail: `${props.headline.settledSales} sudah selesai`,
        },
        {
            icon: 'users',
            label: 'Pelanggan',
            value: props.headline.customers,
            detail: 'Terdaftar di sales desk',
        },
        {
            icon: 'box',
            label: 'Stok Habis',
            value: props.headline.outOfStockProducts,
            detail: 'Perlu diisi ulang',
            tone: props.headline.outOfStockProducts > 0 ? 'text-rose-400' : 'text-emerald-400',
        },
    ];
});

/** Colour per status, matching the tile palette so the two read as one system. */
const STATUS_TONE = {
    draft: 'bg-neutral-400',
    pending: 'bg-admin-warning',
    confirmed: 'bg-admin-primary',
    shipped: 'bg-admin-cyan',
    delivered: 'bg-admin-success',
    cancelled: 'bg-admin-danger',
};

const statusTotal = computed(() =>
    Object.values(props.statusCounts).reduce((sum, value) => sum + value, 0)
);

const statusBars = computed(() =>
    Object.entries(props.statusCounts).map(([status, count]) => ({
        status,
        count,
        share: statusTotal.value === 0 ? 0 : (count / statusTotal.value) * 100,
    }))
);
</script>

<template>
    <Head title="Dashboard" />

    <div class="mb-6">
        <h1 class="text-xl font-medium text-neutral-800">Dashboard</h1>
        <p class="mt-1 text-sm text-neutral-500">
            Ringkasan penjualan, pelanggan, dan stok.
        </p>
    </div>

    <!-- Section tiles -->
    <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
        <Link
            v-for="tile in TILES"
            :key="tile.label"
            :href="routes[tile.route]"
            class="group flex flex-col items-center justify-center gap-2 rounded px-3 py-6 text-center text-white shadow-admin-tile transition-transform hover:-translate-y-0.5 hover:shadow-lg"
            :class="tile.tone"
        >
            <Icon :name="tile.icon" class="size-7" />
            <span class="text-sm font-medium">{{ tile.label }}</span>
        </Link>
    </div>

    <!-- Chart + statistics -->
    <div class="mb-6 grid gap-4 lg:grid-cols-3">
        <PanelSection
            class="lg:col-span-2"
            title="Analisis Penjualan"
            subtitle="Ringkasan 12 bulan terakhir"
        >
            <div class="h-72">
                <RevenueChart :monthly="monthly" />
            </div>
        </PanelSection>

        <div class="grid grid-cols-2 gap-3 self-start">
            <div
                v-for="stat in stats"
                :key="stat.label"
                class="rounded bg-admin-tile px-4 py-5 text-center text-white shadow-admin-tile"
            >
                <Icon :name="stat.icon" class="mx-auto mb-2 size-5 text-white/80" />
                <p class="text-xl font-semibold tabular-nums">{{ stat.value }}</p>
                <p class="mt-0.5 text-xs text-white/60">{{ stat.label }}</p>
                <p v-if="stat.detail" class="mt-1 text-xs" :class="stat.tone ?? 'text-white/40'">
                    {{ stat.detail }}
                </p>
            </div>
        </div>
    </div>

    <!-- Status mix + latest sales -->
    <div class="mb-6 grid gap-4 lg:grid-cols-3">
        <PanelSection title="Status Penjualan" subtitle="Distribusi seluruh ledger">
            <ul class="space-y-2.5">
                <li v-for="bar in statusBars" :key="bar.status">
                    <div class="mb-1 flex items-center justify-between text-xs">
                        <span class="text-neutral-600">{{ bar.status }}</span>
                        <span class="tabular-nums text-neutral-500">{{ bar.count }}</span>
                    </div>
                    <div class="h-2 w-full rounded-full bg-neutral-100">
                        <div
                            class="h-2 rounded-full transition-all"
                            :class="STATUS_TONE[bar.status] ?? 'bg-neutral-400'"
                            :style="{ width: `${bar.share}%` }"
                        />
                    </div>
                </li>
            </ul>
        </PanelSection>

        <PanelSection title="Penjualan Terbaru" subtitle="Enam transaksi terakhir" class="lg:col-span-2">
            <template #action>
                <Link :href="routes.sales" class="text-xs font-medium text-admin-primary hover:underline">
                    Lihat semua
                </Link>
            </template>

            <table class="w-full text-left text-sm">
                <thead class="border-b border-neutral-200 text-xs uppercase tracking-wide text-neutral-500">
                    <tr>
                        <th scope="col" class="py-2 font-medium">Gambar</th>
                        <th scope="col" class="py-2 font-medium">Referensi</th>
                        <th scope="col" class="py-2 font-medium">Pelanggan</th>
                        <th scope="col" class="py-2 font-medium">Status</th>
                        <th scope="col" class="py-2 text-right font-medium">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100">
                    <tr v-for="sale in recentSales" :key="sale.id">
                        <td class="py-2.5">
                            <Link
                                v-if="sale.noteImage"
                                :href="sale.url"
                                class="block h-10 w-10 overflow-hidden rounded border border-neutral-200"
                                :aria-label="`Lihat ${sale.reference}`"
                            >
                                <img
                                    :src="sale.noteImage.url"
                                    :alt="sale.noteImage.alt"
                                    class="h-full w-full object-cover"
                                    loading="lazy"
                                >
                            </Link>
                            <span v-else class="block h-10 w-10 rounded border border-dashed border-neutral-200" aria-hidden="true" />
                        </td>
                        <td class="py-2.5 font-medium text-neutral-800">
                            <Link :href="sale.url" class="hover:text-admin-primary hover:underline">
                                {{ sale.reference }}
                            </Link>
                            <span class="block text-xs font-normal text-neutral-500">{{ sale.soldAt }}</span>
                        </td>
                        <td class="py-2.5 text-neutral-600">{{ sale.customer }}</td>
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

                    <tr v-if="recentSales.length === 0">
                        <td colspan="5" class="py-8 text-center text-sm text-neutral-500">
                            Belum ada penjualan.
                        </td>
                    </tr>
                </tbody>
            </table>
        </PanelSection>
    </div>

    <!-- Slow movers + low stock -->
    <div class="grid gap-4 lg:grid-cols-2">
        <PanelSection title="Paling Lambat Terjual" subtitle="Enam bulan terakhir">
            <ul class="divide-y divide-neutral-100">
                <li v-for="product in slowMovers" :key="product.id" class="flex items-center justify-between py-2.5">
                    <span class="text-sm text-neutral-700">{{ product.name }}</span>
                    <span class="text-xs tabular-nums text-neutral-500">{{ product.unitsSold }} unit</span>
                </li>
            </ul>
        </PanelSection>

        <PanelSection title="Stok Menipis" subtitle="Lima unit atau kurang">
            <ul class="divide-y divide-neutral-100">
                <li v-for="product in lowStock" :key="product.id" class="flex items-center justify-between py-2.5">
                    <Link :href="product.url" class="text-sm text-neutral-700 hover:text-admin-primary hover:underline">
                        {{ product.name }}
                    </Link>
                    <span
                        class="rounded-full px-2 py-0.5 text-xs font-medium tabular-nums"
                        :class="product.stock === 0 ? 'bg-rose-100 text-rose-700' : 'bg-amber-100 text-amber-700'"
                    >
                        {{ product.stock }}
                    </span>
                </li>

                <li v-if="lowStock.length === 0" class="py-8 text-center text-sm text-neutral-500">
                    Semua stok aman.
                </li>
            </ul>
        </PanelSection>
    </div>
</template>