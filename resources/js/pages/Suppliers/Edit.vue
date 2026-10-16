<script setup>
/**
 * Editing a supplier's contact details.
 *
 * Only the supplier's own columns are editable here. Product pairings, unit
 * costs, and lead times belong to the product form and its pivot action; editing
 * contact details must not rewrite purchasing history by accident.
 *
 * The form state uses the column names rather than the camelCase the table was
 * sent in. It used to be renamed on the way out through a `transform`, which meant
 * two spellings of the same field and a mapping that only existed because of it —
 * sharing the fields with the create form is simpler with one name throughout.
 */
import { Head, Link, useForm } from '@inertiajs/vue3';

import AdminLayout from '../../layouts/AdminLayout.vue';
import SupplierFields from '../../components/SupplierFields.vue';
import { useRoutes } from '../../shared/routes.js';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    supplier: { type: Object, required: true },
    updateUrl: { type: String, required: true },
});

const routes = useRoutes();

const form = useForm({
    name: props.supplier.name,
    contact_email: props.supplier.contactEmail ?? '',
    phone: props.supplier.phone ?? '',
});

function submit() {
    form.patch(props.updateUrl);
}
</script>

<template>
    <Head :title="`Ubah ${supplier.name}`" />

    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="text-lg font-semibold tracking-tight">Ubah Supplier</h1>
            <p class="mt-1 text-sm text-neutral-500">
                Perubahan di sini tidak mengubah produk atau riwayat pemasok.
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

        <button
            type="submit"
            class="mt-5 rounded-md bg-admin-primary px-3 py-2 text-sm font-medium text-white hover:bg-admin-primary-dark disabled:cursor-not-allowed disabled:opacity-40"
            :disabled="form.processing"
        >
            Simpan supplier
        </button>
    </form>
</template>