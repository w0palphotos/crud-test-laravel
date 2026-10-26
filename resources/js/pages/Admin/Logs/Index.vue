<script setup>
/**
 * Log browser ala /var/log: pohon direktori storage/logs/ di kiri, isi file
 * yang dipilih di kanan. Aktivitas JSON di-parse jadi tabel; error Monolog
 * di-parse jadi baris dengan level & stack trace agar lebih mudah dibaca
 * daripada raw text.
 */
import { computed, ref, watch } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';

import AdminLayout from '../../../layouts/AdminLayout.vue';
import LogTreeNode from '../../../components/LogTreeNode.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    tree: { type: Array, required: true },
    selectedFile: { type: String, default: null },
    type: { type: String, default: null },
    entries: { type: Array, default: () => [] },
    raw: { type: String, default: null },
    error: { type: String, default: null },
});

const page = usePage();

const actionLabels = {
    created: 'Dibuat',
    updated: 'Diperbarui',
    deleted: 'Dihapus',
    login: 'Masuk',
    logout: 'Keluar',
};

function actionBadge(action) {
    return {
        created: 'bg-emerald-100 text-emerald-800',
        updated: 'bg-amber-100 text-amber-800',
        deleted: 'bg-rose-100 text-rose-800',
        login: 'bg-blue-100 text-blue-800',
        logout: 'bg-neutral-100 text-neutral-800',
    }[action] ?? 'bg-neutral-100 text-neutral-600';
}

function levelBadge(level) {
    const l = (level || '').toUpperCase();
    if (l === 'ERROR' || l === 'CRITICAL' || l === 'ALERT' || l === 'EMERGENCY') {
        return 'bg-rose-100 text-rose-800';
    }
    if (l === 'WARNING' || l === 'NOTICE') {
        return 'bg-amber-100 text-amber-800';
    }
    if (l === 'INFO' || l === 'DEBUG') {
        return 'bg-blue-100 text-blue-800';
    }
    return 'bg-neutral-100 text-neutral-600';
}

function subjectLabel(type) {
    const last = (type || '').split('\\').pop();
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

// Filter tanggal (YYYY-MM-DD) untuk entri yang punya timestamp.
const selectedDate = ref('');

const availableDates = computed(() => {
    const set = new Set();
    for (const e of props.entries) {
        if (e.timestamp) {
            set.add(String(e.timestamp).slice(0, 10));
        }
    }
    return [...set].sort().reverse();
});

const filteredEntries = computed(() => {
    if (!selectedDate.value) return props.entries;
    return props.entries.filter((e) => String(e.timestamp || '').startsWith(selectedDate.value));
});

watch(() => props.selectedFile, () => {
    selectedDate.value = '';
});

function onSelect(path) {
    router.visit('/admin/logs', {
        data: { file: path },
        preserveState: true,
        preserveScroll: true,
        only: ['selectedFile', 'type', 'entries', 'raw', 'error'],
    });
}

function prettyJson(value) {
    if (value === null || value === undefined) return '';
    try {
        return JSON.stringify(value, null, 2);
    } catch (e) {
        return String(value);
    }
}
</script>

<template>
    <Head title="Log Browser" />

    <div class="mt-6">
        <h2 class="text-lg font-semibold tracking-tight">Log Browser</h2>
        <p class="mt-1 text-sm text-neutral-600">
            Penjelajah log ala /var/log: pilih file di pohon direktori kiri,
            isi yang diparse tampil di kanan.
        </p>
    </div>

    <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
        <!-- Tree -->
        <div class="lg:col-span-1 overflow-auto rounded border border-neutral-200 bg-neutral-50 p-3 max-h-[75vh]">
            <div v-if="tree.length === 0" class="text-sm text-neutral-500">
                Tidak ada file log.
            </div>
            <LogTreeNode
                v-for="node in tree"
                :key="node.path"
                :node="node"
                :selected-path="selectedFile"
                @select="onSelect"
            />
        </div>

        <!-- Content -->
        <div class="lg:col-span-2 space-y-4">
            <div v-if="error" class="rounded border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                {{ error }}
            </div>

            <div v-else-if="!selectedFile" class="rounded border border-neutral-200 bg-neutral-50 px-4 py-8 text-center text-sm text-neutral-500">
                Pilih file log di pohon direktori untuk melihat isinya.
            </div>

            <template v-else>
                <div class="flex items-center justify-between gap-2">
                    <div class="text-sm font-medium text-neutral-700 truncate">
                        {{ selectedFile }}
                    </div>
                    <div class="flex items-center gap-2">
                        <select
                            v-if="availableDates.length > 1"
                            v-model="selectedDate"
                            class="rounded border border-neutral-300 px-2 py-1 text-xs"
                        >
                            <option value="">Semua tanggal</option>
                            <option v-for="d in availableDates" :key="d" :value="d">{{ d }}</option>
                        </select>
                        <span class="text-xs text-neutral-400">
                            {{ filteredEntries.length }} entri
                        </span>
                    </div>
                </div>

                <!-- Activity: table -->
                <div v-if="type === 'activity'" class="overflow-auto rounded border border-neutral-200 bg-white max-h-[65vh]">
                    <table class="w-full text-left text-xs">
                        <thead class="border-b border-neutral-200 bg-neutral-100 text-neutral-500 sticky top-0">
                            <tr>
                                <th class="px-3 py-2 font-medium">Waktu</th>
                                <th class="px-3 py-2 font-medium">Pengguna</th>
                                <th class="px-3 py-2 font-medium">Aksi</th>
                                <th class="px-3 py-2 font-medium">Data</th>
                                <th class="px-3 py-2 font-medium">Request ID</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100">
                            <tr v-for="(e, i) in filteredEntries" :key="i" class="hover:bg-neutral-50">
                                <td class="px-3 py-2 text-neutral-600 whitespace-nowrap">{{ e.timestamp }}</td>
                                <td class="px-3 py-2">
                                    <div class="font-medium">{{ e.email }}</div>
                                    <div class="text-neutral-400">{{ e.ip_address }}</div>
                                </td>
                                <td class="px-3 py-2">
                                    <span :class="['inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium', actionBadge(e.action)]">
                                        {{ actionLabels[e.action] ?? e.action }}
                                    </span>
                                </td>
                                <td class="px-3 py-2">
                                    <div class="font-medium">{{ subjectLabel(e.subject_type) }}</div>
                                    <div class="text-neutral-400">{{ e.subject_name }}</div>
                                </td>
                                <td class="px-3 py-2 font-mono text-neutral-400 truncate max-w-[120px]">
                                    {{ e.request_id }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-if="filteredEntries.length === 0" class="px-4 py-8 text-center text-sm text-neutral-500">
                        Tidak ada entri.
                    </div>
                </div>

                <!-- Error: parsed entries -->
                <div v-else-if="type === 'error'" class="space-y-2 max-h-[65vh] overflow-auto">
                    <div
                        v-for="(e, i) in filteredEntries"
                        :key="i"
                        class="rounded border border-neutral-200 bg-white p-3"
                    >
                        <div class="flex items-center gap-2 text-xs">
                            <span class="text-neutral-500 whitespace-nowrap">{{ e.timestamp }}</span>
                            <span :class="['inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium', levelBadge(e.level)]">
                                {{ e.level }}
                            </span>
                            <span class="text-neutral-400">{{ e.environment }}</span>
                        </div>
                        <div class="mt-1 text-sm text-neutral-800 break-words">{{ e.message }}</div>
                        <pre v-if="e.stack" class="mt-2 overflow-auto rounded bg-neutral-100 p-2 text-xs text-neutral-600 whitespace-pre-wrap">{{ e.stack }}</pre>
                    </div>
                    <div v-if="filteredEntries.length === 0" class="rounded border border-neutral-200 bg-neutral-50 px-4 py-8 text-center text-sm text-neutral-500">
                        Tidak ada entri.
                    </div>
                </div>

                <!-- Raw fallback -->
                <details class="rounded border border-neutral-200 bg-neutral-50">
                    <summary class="cursor-pointer px-4 py-2 text-xs font-medium text-neutral-600 hover:bg-neutral-100">
                        Lihat raw text
                    </summary>
                    <pre class="overflow-auto p-4 text-xs text-neutral-700 whitespace-pre-wrap max-h-[50vh]">{{ raw }}</pre>
                </details>
            </template>
        </div>
    </div>
</template>