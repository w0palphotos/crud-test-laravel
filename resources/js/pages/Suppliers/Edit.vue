<script setup>
/**
 * Editing a supplier's contact details.
 *
 * Only the supplier's own columns are editable here. Product pairings, unit
 * costs, and lead times belong to the product form and its pivot action; editing
 * contact details must not rewrite purchasing history by accident.
 */
import { Head, Link, useForm } from '@inertiajs/vue3';

import AdminLayout from '../../layouts/AdminLayout.vue';
import { useRoutes } from '../../shared/routes.js';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    supplier: { type: Object, required: true },
    updateUrl: { type: String, required: true },
});

const routes = useRoutes();

const form = useForm({
    name: props.supplier.name,
    contactEmail: props.supplier.contactEmail ?? '',
    phone: props.supplier.phone ?? '',
});

function submit() {
    form.transform((data) => ({
        name: data.name,
        contact_email: data.contactEmail,
        phone: data.phone,
    })).patch(props.updateUrl);
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
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="name" class="block text-sm font-medium">Nama</label>
                <input
                    id="name"
                    v-model="form.name"
                    type="text"
                    required
                    maxlength="255"
                    class="mt-1.5 w-full rounded border bg-admin-surface px-3 py-2 text-sm focus:border-admin-primary focus:outline-none"
                    :class="form.errors.name ? 'border-rose-400' : 'border-neutral-300'"
                >
                <p v-if="form.errors.name" class="mt-1.5 text-sm text-rose-600">{{ form.errors.name }}</p>
            </div>

            <div>
                <label for="contactEmail" class="block text-sm font-medium">Email kontak</label>
                <input
                    id="contactEmail"
                    v-model="form.contactEmail"
                    type="email"
                    maxlength="255"
                    class="mt-1.5 w-full rounded border bg-admin-surface px-3 py-2 text-sm focus:border-admin-primary focus:outline-none"
                    :class="form.errors.contact_email ? 'border-rose-400' : 'border-neutral-300'"
                >
                <p v-if="form.errors.contact_email" class="mt-1.5 text-sm text-rose-600">{{ form.errors.contact_email }}</p>
            </div>

            <div>
                <label for="phone" class="block text-sm font-medium">Telepon</label>
                <input
                    id="phone"
                    v-model="form.phone"
                    type="tel"
                    maxlength="24"
                    class="mt-1.5 w-full rounded border bg-admin-surface px-3 py-2 text-sm focus:border-admin-primary focus:outline-none"
                    :class="form.errors.phone ? 'border-rose-400' : 'border-neutral-300'"
                >
                <p v-if="form.errors.phone" class="mt-1.5 text-sm text-rose-600">{{ form.errors.phone }}</p>
            </div>
        </div>

        <button
            type="submit"
            class="mt-5 rounded-md bg-admin-primary px-3 py-2 text-sm font-medium text-white hover:bg-admin-primary-dark disabled:cursor-not-allowed disabled:opacity-40"
            :disabled="form.processing"
        >
            Simpan supplier
        </button>
    </form>
</template>
