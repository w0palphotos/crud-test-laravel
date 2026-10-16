<script setup>
/**
 * Editing a customer and their commercial profile.
 *
 * Two forms post to two endpoints because the rows have different lifecycles: the
 * customer exists from the first order, while the profile is created later when
 * the desk onboards them. Merging them would hide whether a save created,
 * updated, or left untouched the one-to-one profile.
 *
 * The fields themselves live in CustomerFields and CustomerProfileFields, because
 * the create form draws exactly the same inputs against the same rules.
 */
import { Head, Link, useForm } from '@inertiajs/vue3';

import AdminLayout from '../../layouts/AdminLayout.vue';
import CustomerFields from '../../components/CustomerFields.vue';
import CustomerProfileFields from '../../components/CustomerProfileFields.vue';
import { useRoutes } from '../../shared/routes.js';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    customer: { type: Object, required: true },
    profile: { type: Object, default: null },
    cities: { type: Array, required: true },
    updateUrl: { type: String, required: true },
    profileUrl: { type: String, required: true },
});

const routes = useRoutes();

const details = useForm({
    name: props.customer.name,
    email: props.customer.email,
    phone: props.customer.phone ?? '',
});

const profile = useForm({
    city_id: props.profile?.city_id ?? '',
    address: props.profile?.address ?? '',
    tax_id: props.profile?.tax_id ?? '',
    credit_limit: props.profile?.credit_limit ?? '',
    payment_terms: props.profile?.payment_terms ?? '',
});

function saveDetails() {
    details.patch(props.updateUrl);
}

function saveProfile() {
    profile.patch(props.profileUrl);
}
</script>

<template>
    <Head :title="`Ubah ${customer.name}`" />

    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="text-lg font-semibold tracking-tight">Ubah Pelanggan</h1>
            <p class="mt-1 text-sm text-neutral-500">
                Perubahan di sini tidak mengubah riwayat penjualan pelanggan.
            </p>
        </div>

        <Link
            :href="routes.customers"
            class="shrink-0 rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium hover:bg-neutral-100"
        >
            Kembali
        </Link>
    </div>

    <div class="mt-6 grid max-w-3xl gap-6">
        <form class="rounded bg-admin-surface p-5 shadow-admin-card" @submit.prevent="saveDetails">
            <h2 class="text-sm font-semibold">Data pelanggan</h2>

            <div class="mt-4">
                <CustomerFields :form="details" />
            </div>

            <button
                type="submit"
                class="mt-5 rounded-md bg-admin-primary px-3 py-2 text-sm font-medium text-white hover:bg-admin-primary-dark disabled:cursor-not-allowed disabled:opacity-40"
                :disabled="details.processing"
            >
                Simpan pelanggan
            </button>
        </form>

        <form class="rounded bg-admin-surface p-5 shadow-admin-card" @submit.prevent="saveProfile">
            <h2 class="text-sm font-semibold">Profil komersial</h2>
            <p class="mt-1 text-xs text-neutral-500">
                Profil dibuat saat pertama kali disimpan; penyimpanan berikutnya memperbarui profil yang sama.
            </p>

            <div class="mt-4">
                <CustomerProfileFields :form="profile" :cities="cities" />
            </div>

            <button
                type="submit"
                class="mt-5 rounded-md bg-admin-primary px-3 py-2 text-sm font-medium text-white hover:bg-admin-primary-dark disabled:cursor-not-allowed disabled:opacity-40"
                :disabled="profile.processing"
            >
                Simpan profil
            </button>
        </form>
    </div>
</template>