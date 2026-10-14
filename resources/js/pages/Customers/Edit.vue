<script setup>
/**
 * Editing a customer and their commercial profile.
 *
 * Two forms post to two endpoints because the rows have different lifecycles: the
 * customer exists from the first order, while the profile is created later when
 * the desk onboards them. Merging them would hide whether a save created,
 * updated, or left untouched the one-to-one profile.
 */
import { Head, Link, useForm } from '@inertiajs/vue3';

import AdminLayout from '../../layouts/AdminLayout.vue';
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

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="name" class="block text-sm font-medium">Nama</label>
                    <input
                        id="name"
                        v-model="details.name"
                        type="text"
                        required
                        maxlength="255"
                        class="mt-1.5 w-full rounded border bg-admin-surface px-3 py-2 text-sm focus:border-admin-primary focus:outline-none"
                        :class="details.errors.name ? 'border-rose-400' : 'border-neutral-300'"
                    >
                    <p v-if="details.errors.name" class="mt-1.5 text-sm text-rose-600">{{ details.errors.name }}</p>
                </div>

                <div>
                    <label for="email" class="block text-sm font-medium">Email</label>
                    <input
                        id="email"
                        v-model="details.email"
                        type="email"
                        required
                        maxlength="255"
                        class="mt-1.5 w-full rounded border bg-admin-surface px-3 py-2 text-sm focus:border-admin-primary focus:outline-none"
                        :class="details.errors.email ? 'border-rose-400' : 'border-neutral-300'"
                    >
                    <p v-if="details.errors.email" class="mt-1.5 text-sm text-rose-600">{{ details.errors.email }}</p>
                </div>

                <div>
                    <label for="phone" class="block text-sm font-medium">Telepon</label>
                    <input
                        id="phone"
                        v-model="details.phone"
                        type="tel"
                        maxlength="24"
                        class="mt-1.5 w-full rounded border bg-admin-surface px-3 py-2 text-sm focus:border-admin-primary focus:outline-none"
                        :class="details.errors.phone ? 'border-rose-400' : 'border-neutral-300'"
                    >
                    <p v-if="details.errors.phone" class="mt-1.5 text-sm text-rose-600">{{ details.errors.phone }}</p>
                </div>
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

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="city_id" class="block text-sm font-medium">Kota</label>
                    <select
                        id="city_id"
                        v-model="profile.city_id"
                        class="mt-1.5 w-full rounded border bg-admin-surface px-3 py-2 text-sm focus:border-admin-primary focus:outline-none"
                        :class="profile.errors.city_id ? 'border-rose-400' : 'border-neutral-300'"
                    >
                        <option value="">Pilih kota</option>
                        <option v-for="city in cities" :key="city.id" :value="city.id">
                            {{ city.name }}
                        </option>
                    </select>
                    <p v-if="profile.errors.city_id" class="mt-1.5 text-sm text-rose-600">{{ profile.errors.city_id }}</p>
                </div>

                <div>
                    <label for="tax_id" class="block text-sm font-medium">NPWP</label>
                    <input
                        id="tax_id"
                        v-model="profile.tax_id"
                        type="text"
                        maxlength="32"
                        class="mt-1.5 w-full rounded border bg-admin-surface px-3 py-2 text-sm focus:border-admin-primary focus:outline-none"
                        :class="profile.errors.tax_id ? 'border-rose-400' : 'border-neutral-300'"
                    >
                    <p v-if="profile.errors.tax_id" class="mt-1.5 text-sm text-rose-600">{{ profile.errors.tax_id }}</p>
                </div>

                <div class="sm:col-span-2">
                    <label for="address" class="block text-sm font-medium">Alamat</label>
                    <textarea
                        id="address"
                        v-model="profile.address"
                        rows="2"
                        maxlength="500"
                        class="mt-1.5 w-full rounded border bg-admin-surface px-3 py-2 text-sm focus:border-admin-primary focus:outline-none"
                        :class="profile.errors.address ? 'border-rose-400' : 'border-neutral-300'"
                    ></textarea>
                    <p v-if="profile.errors.address" class="mt-1.5 text-sm text-rose-600">{{ profile.errors.address }}</p>
                </div>

                <div>
                    <label for="credit_limit" class="block text-sm font-medium">Limit kredit</label>
                    <input
                        id="credit_limit"
                        v-model="profile.credit_limit"
                        type="number"
                        step="0.01"
                        min="0"
                        class="mt-1.5 w-full rounded border bg-admin-surface px-3 py-2 text-sm tabular-nums focus:border-admin-primary focus:outline-none"
                        :class="profile.errors.credit_limit ? 'border-rose-400' : 'border-neutral-300'"
                    >
                    <p v-if="profile.errors.credit_limit" class="mt-1.5 text-sm text-rose-600">{{ profile.errors.credit_limit }}</p>
                </div>

                <div>
                    <label for="payment_terms" class="block text-sm font-medium">Syarat bayar</label>
                    <input
                        id="payment_terms"
                        v-model="profile.payment_terms"
                        type="text"
                        maxlength="64"
                        class="mt-1.5 w-full rounded border bg-admin-surface px-3 py-2 text-sm focus:border-admin-primary focus:outline-none"
                        :class="profile.errors.payment_terms ? 'border-rose-400' : 'border-neutral-300'"
                    >
                    <p v-if="profile.errors.payment_terms" class="mt-1.5 text-sm text-rose-600">{{ profile.errors.payment_terms }}</p>
                </div>
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
