<script setup>
/**
 * Adding a supplier.
 *
 * One form, one request, three columns — StoreSupplierRequest's rules, unchanged.
 * There is no profile here the way a customer has one: what a supplier is paid and
 * how long they take is a fact about a supplier paired with a particular product,
 * so it lives on that pairing and is not written here.
 */
import { Head, Link, useForm } from '@inertiajs/vue3';

import AdminLayout from '../../layouts/AdminLayout.vue';
import SupplierFields from '../../components/SupplierFields.vue';
import { useRoutes } from '../../shared/routes.js';

defineOptions({ layout: AdminLayout });

const routes = useRoutes();

const form = useForm({
    name: '',
    contact_email: '',
    phone: '',
});

function submit() {
    form.post(routes.suppliersStore);
}
</script>

<template>
    <Head title="Supplier Baru" />

    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="text-lg font-semibold tracking-tight">Supplier Baru</h1>
            <p class="mt-1 text-sm text-neutral-500">
                Harga dan lead time per produk diatur dari form produk.
            </p>
        </div>

        <Link
            :href="routes.suppliers"
            class="shrink-0 rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium hover:bg-neutral-100"
        >
            Kembali
        </Link>
    </div>

    <form class="mt-6 max-w-3xl rounded bg-admin-surface p-5 shadow-admin-card" @submit.prevent="submit">
        <SupplierFields :form="form" />

        <div class="mt-5 flex items-center gap-2">
            <button
                type="submit"
                class="rounded-md bg-admin-primary px-3 py-2 text-sm font-medium text-white hover:bg-admin-primary-dark disabled:cursor-not-allowed disabled:opacity-40"
                :disabled="form.processing"
            >
                Buat supplier
            </button>

            <Link
                :href="routes.suppliers"
                class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium hover:bg-neutral-100"
            >
                Batal
            </Link>
        </div>
    </form>
</template>