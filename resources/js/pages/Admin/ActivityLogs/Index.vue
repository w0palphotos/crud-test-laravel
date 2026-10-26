<script setup>
/**
 * Activity log for debugging: who did what, when, and from where.
 *
 * Only administrators reach this page (route is behind EnsureAdmin). Rows come
 * from activity_logs, which the observer writes on every create/delete of the
 * entities sales may touch. The same events are also written to
 * storage/logs/activity.log, separate from laravel.log.
 */
import { computed, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';

import AdminLayout from '../../../layouts/AdminLayout.vue';
import { useRoutes } from '../../../shared/routes.js';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    logs: { type: Object, required: true },
    filters: { type: Object, required: true },
    actions: { type: Array, required: true },
});

const page = usePage();
const routes = useRoutes();

const actionLabels = {
    created: 'Dibuat',
    updated: 'Diperbarui',
    deleted: 'Dihapus',
    login: 'Masuk',
    logout: 'Keluar',
};

/** Colour chip per action. */
function actionBadge(action) {
    return {
        created: 'bg-emerald-100 text-emerald-800',
        updated: 'bg-amber-100 text-amber-800',
        deleted: 'bg-rose-100 text-rose-800',
        login: 'bg-blue-100 text-blue-800',
        logout: 'bg-neutral-100 text-neutral-800',
    }[action] ?? 'bg-neutral-100 text-neutral-600';
}

function subjectLabel(type) {
    const last = type.split('\\').pop();
    const map = {
        Product: 'Produk',
        Customer: 'Pelanggan',
        Supplier: 'Supplier',
        Sale: 'Penjualan',
        City: 'Kota',
        Category: 'Kategori',
        Tag: 'Tag',
        Salesperson: 'Salesperson',
        ProductImage: 'Gambar Produk',
        CustomerProfile: 'Profil Pelanggan',
        SaleImage: 'Gambar Penjualan',
        SaleDocument: 'Dokumen Penjualan',
        SaleStatusEvent: 'Riwayat Status',
    };
    return map[last] ?? last;
}

const selectedLog = ref(null);

function openDetail(log) {
    selectedLog.value = log;
}

function closeDetail() {
    selectedLog.value = null;
}
</script>

<template>
    <Head title="Log Aktivitas" />

    <div class="mt-6">
        <h2 class="text-lg font-semibold tracking-tight">Log Aktivitas</h2>
        <p class="mt-1 text-sm text-neutral-600">
            Riwayat siapa yang membuat, memperbarui atau menghapus data. Untuk
            debugging — terpisah dari laravel.log.
        </p>
    </div>

    <form class="mt-8 grid grid-cols-1 gap-4 sm:grid-cols-3 lg:grid-cols-4" role="search">
        <div class="col-span-1 sm:col-span-2">
            <label for="email" class="block text-sm font-medium text-neutral-700">
                Email pengguna
            </label>
            <input
                id="email"
                v-model="filters.email"
                type="search"
                placeholder="Nama atau email..."
                class="mt-1 block w-full rounded-md border border-neutral-300 px-3 py-2 text-sm focus:ring-2 focus:ring-admin-primary focus:border-admin-primary"
            >
        </div>

        <div>
            <label for="action" class="block text-sm font-medium text-neutral-700">
                Aksi
            </label>
            <select
                id="action"
                v-model="filters.action"
                class="mt-1 block w-full rounded-md border border-neutral-300 px-3 py-2 text-sm focus:ring-2 focus:ring-admin-primary focus:border-admin-primary"
            >
                <option value="">Semua aksi</option>
                <option v-for="action in actions" :key="action" :value="action">
                    {{ actionLabels[action] }}
                </option>
            </select>
        </div>

        <div>
            <label for="subject" class="block text-sm font-medium text-neutral-700">
                Tipe data
            </label>
            <input
                id="subject"
                v-model="filters.subject"
                type="search"
                placeholder="Produk, Pelanggan, ..."
                class="mt-1 block w-full rounded-md border border-neutral-300 px-3 py-2 text-sm focus:ring-2 focus:ring-admin-primary focus:border-admin-primary"
            >
        </div>

        <div class="col-span-1 sm:col-span-1 flex items-end">
            <button
                type="submit"
                class="rounded-md bg-admin-primary px-3 py-2 text-sm font-medium text-white hover:bg-admin-primary-dark"
            >
                Filter
            </button>
        </div>
    </form>

    <div class="mt-8 overflow-hidden rounded border border-neutral-200 bg-neutral-50">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-neutral-200 bg-neutral-100 text-xs uppercase tracking-wide text-neutral-500">
                <tr>
                    <th class="px-4 py-3 font-medium">Waktu</th>
                    <th class="px-4 py-3 font-medium">Pengguna</th>
                    <th class="px-4 py-3 font-medium">Aksi</th>
                    <th class="px-4 py-3 font-medium">Data</th>
                    <th class="px-4 py-3 font-medium text-right">Detail</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200">
                <tr v-for="log in logs.data" :key="log.id" class="hover:bg-neutral-100">
                    <td class="px-4 py-3 text-neutral-600">{{ log.created_at }}</td>
                    <td class="px-4 py-3">
                        <div class="font-medium">{{ log.email }}</div>
                        <div class="text-xs text-neutral-500">{{ log.user?.is_admin ? 'Admin' : 'Sales' }}</div>
                    </td>
                    <td class="px-4 py-3">
                        <span
                            :class="['inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium', actionBadge(log.action)]"
                        >
                            {{ actionLabels[log.action] }}
                        </span>
                    </td>
                    <td class="px-4 py-3">
                        <div class="font-medium">{{ subjectLabel(log.subject_type) }}</div>
                        <div class="text-xs text-neutral-500">{{ log.subject_name }}</div>
                    </td>
                    <td class="px-4 py-3 text-right">
                        <button
                            type="button"
                            @click="openDetail(log)"
                            class="text-sm text-admin-primary hover:underline"
                            :title="'ID: ' + log.id + ', IP: ' + log.ip_address"
                        >
                            Lihat
                        </button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <div v-if="logs.last_page > 1" class="mt-6 flex items-center justify-between">
        <div class="flex-1 flex justify-between sm:hidden">
            <button
                v-if="logs.current_page > 1"
                @click="logs.prev_page_url && (window.location.href = logs.prev_page_url)"
                class="relative inline-flex items-center rounded-md border border-neutral-300 bg-white px-4 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50"
            >
                Sebelumnya
            </button>
            <button
                v-if="logs.current_page < logs.last_page"
                @click="logs.next_page_url && (window.location.href = logs.next_page_url)"
                class="relative inline-flex items-center rounded-md border border-neutral-300 bg-white px-4 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50"
            >
                Selanjutnya
            </button>
        </div>

        <nav class="relative z-10 inline-flex items-center space-x-1 sm:hidden" aria-label="Pagination">
            <template v-if="logs.current_page > 1">
                <button
                    @click="logs.prev_page_url && (window.location.href = logs.prev_page_url)"
                    class="relative inline-flex items-center rounded-l-md border border-neutral-300 bg-white px-4 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50"
                >
                    Sebelumnya
                </button>
            </template>
            <span class="relative inline-flex items-center rounded-r-md border border-neutral-300 bg-white px-4 py-2 text-sm font-medium text-neutral-700">
                Halaman {{ logs.current_page }} dari {{ logs.last_page }}
            </span>
        </nav>
    </div>

    <div v-if="selectedLog" class="fixed inset-0 z-50 flex items-center justify-center">
        <div
            class="fixed inset-0 bg-black bg-opacity-50 transition-opacity"
            @click="closeDetail"
        ></div>

        <div
            class="relative bg-white rounded-lg shadow-2xl w-full max-w-2xl mx-4 max-h-[90vh] overflow-hidden flex flex-col"
        >
            <div class="flex items-center justify-between px-6 py-4 border-b border-neutral-200 bg-neutral-50">
                <h3 class="text-lg font-semibold text-neutral-900">Detail Log Aktivitas</h3>
                <button
                    type="button"
                    @click="closeDetail"
                    class="text-neutral-400 hover:text-neutral-600 transition"
                >
                    <svg
                        class="h-6 w-6"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke-width="2"
                        stroke="currentColor"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            d="M6 18L18 6M6 6l12 12"
                        />
                    </svg>
                </button>
            </div>

            <div class="px-6 py-6 overflow-y-auto flex-1">
                <div class="space-y-4">
                    <div class="flex items-start gap-4 text-sm">
                        <div class="w-24 text-neutral-500">Waktu</div>
                        <div class="text-neutral-700">{{ selectedLog.created_at }}</div>
                    </div>

                    <div class="flex items-start gap-4 text-sm">
                        <div class="w-24 text-neutral-500">Pengguna</div>
                        <div>
                            <div class="font-medium text-neutral-900">{{ selectedLog.email }}</div>
                            <div class="text-xs text-neutral-500">
                                {{ selectedLog.user ? (selectedLog.user.is_admin ? 'Admin' : 'Sales') : '-' }}
                            </div>
                        </div>
                    </div>

                    <div class="flex items-start gap-4 text-sm">
                        <div class="w-24 text-neutral-500">Aksi</div>
                        <div class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                            :class="actionBadge(selectedLog.action)">
                            {{ actionLabels[selectedLog.action] }}
                        </div>
                    </div>

                    <div class="flex items-start gap-4 text-sm">
                        <div class="w-24 text-neutral-500">Tipe data</div>
                        <div class="text-neutral-700">{{ subjectLabel(selectedLog.subject_type) }}</div>
                    </div>

                    <div class="flex items-start gap-4 text-sm">
                        <div class="w-24 text-neutral-500">Nama data</div>
                        <div class="text-neutral-700">{{ selectedLog.subject_name }}</div>
                    </div>

                    <div class="flex items-start gap-4 text-sm">
                        <div class="w-24 text-neutral-500">ID data</div>
                        <div class="text-neutral-700">{{ selectedLog.subject_id }}</div>
                    </div>

                    <div class="flex items-start gap-4 text-sm">
                        <div class="w-24 text-neutral-500">IP Address</div>
                        <div class="text-neutral-700">{{ selectedLog.ip_address }}</div>
                    </div>

                    <div class="flex items-start gap-4 text-sm">
                        <div class="w-24 text-neutral-500">User Agent</div>
                        <div class="text-neutral-700 truncate max-w-xs">{{ selectedLog.user_agent }}</div>
                    </div>

                    <div class="flex items-start gap-4 text-sm">
                        <div class="w-24 text-neutral-500">Properti</div>
                        <div
                            v-if="selectedLog.properties"
                            class="text-neutral-700 text-xs font-mono bg-neutral-100 rounded p-3 break-all max-w-full"
                        >
                            {{ JSON.stringify(selectedLog.properties, null, 2) }}
                        </div>
                        <div v-else class="text-neutral-400 text-xs">-</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
