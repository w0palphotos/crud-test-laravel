<script setup>
/**
 * The customer list.
 *
 * Read-only: editing a customer is the account form's job, and the one-to-one
 * profile is what this list is mostly showing, so onboarding is deliberately not
 * a route here.
 */
import { computed, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

import AdminLayout from '../../layouts/AdminLayout.vue';
import PanelSection from '../../components/PanelSection.vue';
import { formatRupiah } from '../../shared/rupiah.js';
import { useRoutes } from '../../shared/routes.js';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    customers: { type: Object, required: true },
    search: { type: String, default: '' },
});

const routes = useRoutes();

const filter = useForm({ q: props.search });

watch(() => props.search, (value) => {
    filter.q = value;
});

function applyFilter() {
    filter.get(routes.customers, { preserveState: true, replace: true });
}

function clearFilter() {
    filter.q = '';
    applyFilter();
}

const pageLinks = computed(() => props.customers.links.filter((link) => link.label === String(link.page)));
</script>

<template>
    <Head title="Pelanggan" />

    <div class="mb-6">
        <h1 class="text-xl font-medium text-neutral-800">Pelanggan</h1>
        <p class="mt-1 text-sm text-neutral-500">
            {{ customers.total }} pelanggan terdaftar.
        </p>
    </div>

    <PanelSection title="Daftar Pelanggan" subtitle="Beserta profil komersial">
        <form class="mb-4 flex flex-wrap gap-2" role="search" @submit.prevent="applyFilter">
            <label for="q" class="sr-only">Cari pelanggan</label>
            <input
                id="q"
                v-model="filter.q"
                type="search"
                placeholder="Nama atau email"
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
            <table class="w-full min-w-[42rem] text-left text-sm">
                <thead class="border-b border-neutral-200 text-xs uppercase tracking-wide text-neutral-500">
                    <tr>
                        <th scope="col" class="py-2 font-medium">Pelanggan</th>
                        <th scope="col" class="py-2 font-medium">Kota</th>
                        <th scope="col" class="py-2 font-medium">Syarat Bayar</th>
                        <th scope="col" class="py-2 text-right font-medium">Limit Kredit</th>
                        <th scope="col" class="py-2 text-right font-medium">Transaksi</th>
                        <th scope="col" class="py-2 text-right font-medium">Aksi</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-neutral-100">
                    <tr v-for="customer in customers.data" :key="customer.id">
                        <td class="py-2.5">
                            <span class="font-medium text-neutral-800">{{ customer.name }}</span>
                            <span class="block text-xs text-neutral-500">{{ customer.email }}</span>
                        </td>
                        <td class="py-2.5 text-neutral-600">{{ customer.city ?? '-' }}</td>
                        <td class="py-2.5 text-neutral-600">{{ customer.onboarded ? customer.paymentTerms : '-' }}</td>
                        <td class="py-2.5 text-right tabular-nums text-neutral-800">
                            <!--
                                A customer with no profile is not a customer with a
                                zero limit, so the two read differently. A dash means
                                nobody has worked out how much they may owe.
                            -->
                            <span v-if="!customer.onboarded" class="text-xs text-amber-600">
                                Belum onboarding
                            </span>
                            <span v-else>Rp {{ formatRupiah(customer.creditLimit) }}</span>
                        </td>
                        <td class="py-2.5 text-right tabular-nums text-neutral-600">{{ customer.salesCount }}</td>
                        <td class="py-2.5 text-right">
                            <Link :href="customer.editUrl" class="text-admin-primary hover:underline">
                                Ubah
                            </Link>
                        </td>
                    </tr>

                    <tr v-if="customers.data.length === 0">
                        <td colspan="6" class="py-10 text-center text-sm text-neutral-500">
                            Tidak ada pelanggan yang cocok.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav v-if="customers.last_page > 1" class="mt-4 flex justify-end gap-1 text-sm">
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