<script setup>
/**
 * The supplier list.
 *
 * The product count comes from a withCount, so it is one extra column on the same
 * query rather than N+1 counting as rows are rendered.
 */
import { computed, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

import AdminLayout from '../../layouts/AdminLayout.vue';
import PanelSection from '../../components/PanelSection.vue';
import { useRoutes } from '../../shared/routes.js';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    /** Whether this account may change a supplier. From the server, not guessed. */
    can: { type: Object, required: true },
    suppliers: { type: Object, required: true },
    search: { type: String, default: '' },
});

const routes = useRoutes();

const filter = useForm({ q: props.search });

watch(() => props.search, (value) => {
    filter.q = value;
});

function applyFilter() {
    filter.get(routes.suppliers, { preserveState: true, replace: true });
}

function clearFilter() {
    filter.q = '';
    applyFilter();
}

const pageLinks = computed(() => props.suppliers.links.filter((link) => link.label === String(link.page)));
</script>

<template>
    <Head title="Supplier" />

    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-medium text-neutral-800">Supplier</h1>
            <p class="mt-1 text-sm text-neutral-500">
                {{ suppliers.total }} pemasok terdaftar.
            </p>
        </div>

        <!-- Removed rather than disabled for a reader; see docs/12. -->
        <Link
            v-if="can.write"
            :href="routes.suppliersCreate"
            class="rounded bg-admin-primary px-3 py-2 text-sm font-medium text-white hover:bg-admin-primary-dark"
        >
            Supplier Baru
        </Link>
    </div>

    <PanelSection title="Daftar Supplier" subtitle="Mitra pemasok untuk produk Anda">
        <form class="mb-4 flex flex-wrap gap-2" role="search" @submit.prevent="applyFilter">
            <label for="q" class="sr-only">Cari supplier</label>
            <input
                id="q"
                v-model="filter.q"
                type="search"
                placeholder="Nama atau email kontak"
                class="w-full max-w-xs rounded border border-neutral-300 px-3 py-2 text-sm focus:border-admin-primary focus:outline-none"
            >
            <button
                type="submit"
                class="rounded bg-admin-primary px-3 py-2 text-sm font-medium text-white hover:bg-admin-primary-dark"
            >
                Cari
            </button>
            <button
                v-if="filter.q !== ''"
                type="button"
                class="px-2 py-2 text-sm text-neutral-500 hover:underline"
                @click="clearFilter"
            >
                Reset
            </button>
        </form>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[36rem] text-left text-sm">
                <thead class="border-b border-neutral-200 text-xs uppercase tracking-wide text-neutral-500">
                    <tr>
                        <th scope="col" class="py-2 font-medium">Supplier</th>
                        <th scope="col" class="py-2 font-medium">Kontak</th>
                        <th scope="col" class="py-2 font-medium">Telepon</th>
                        <th scope="col" class="py-2 text-right font-medium">Produk</th>
                        <!-- Only drawn when there is something under it. -->
                        <th v-if="can.write" scope="col" class="py-2 text-right font-medium">Aksi</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-neutral-100">
                    <tr v-for="supplier in suppliers.data" :key="supplier.id">
                        <td class="py-2.5 font-medium text-neutral-800">{{ supplier.name }}</td>
                        <td class="py-2.5 text-neutral-600">{{ supplier.contactEmail ?? '-' }}</td>
                        <td class="py-2.5 text-neutral-600">{{ supplier.phone ?? '-' }}</td>
                        <td class="py-2.5 text-right tabular-nums text-neutral-600">
                            {{ supplier.productsCount }}
                        </td>
                        <td v-if="can.write" class="py-2.5 text-right">
                            <Link :href="supplier.editUrl" class="text-admin-primary hover:underline">
                                Ubah
                            </Link>
                        </td>
                    </tr>

                    <tr v-if="suppliers.data.length === 0">
                        <td :colspan="can.write ? 5 : 4" class="py-10 text-center text-sm text-neutral-500">
                            Tidak ada supplier yang cocok.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav v-if="suppliers.last_page > 1" class="mt-4 flex justify-end gap-1 text-sm">
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
        </nav>
    </PanelSection>
</template>