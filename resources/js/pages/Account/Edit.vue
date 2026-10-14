<script setup>
/**
 * Account settings.
 *
 * Details and password are two forms posting to the same endpoint, and that is
 * not tidiness: UpdateAccountRequest demands current_password only when a
 * password is present, so a details-only save does not have to supply one.
 * Merging them into a single form would make every save ask for the current
 * password to change a display name.
 */
import { Head, Form, useForm } from '@inertiajs/vue3';

import AdminLayout from '../../layouts/AdminLayout.vue';
import ConfirmDialog from '../../components/ConfirmDialog.vue';
import { useRoutes } from '../../shared/routes.js';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    user: { type: Object, required: true },
});

const routes = useRoutes();

const details = useForm({
    name: props.user.name,
    email: props.user.email,
});

const password = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

function saveDetails() {
    details.patch(routes.accountUpdate);
}

function savePassword() {
    password.patch(routes.accountUpdate, {
        onSuccess: () => password.reset(),
    });
}
</script>

<template>
    <Head title="Akun" />

    <h1 class="text-lg font-semibold tracking-tight">Akun</h1>

    <div class="mt-6 max-w-xl space-y-8">
        <form class="rounded bg-admin-surface p-5 shadow-admin-card" @submit.prevent="saveDetails">
            <h2 class="text-sm font-semibold">Detail</h2>

            <div class="mt-4 space-y-4">
                <div>
                    <label for="name" class="block text-sm font-medium">Nama</label>
                    <input
                        id="name"
                        v-model="details.name"
                        type="text"
                        required
                        maxlength="255"
                        autocomplete="username"
                        class="mt-1.5 w-full rounded-md border px-3 py-2 text-sm"
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
                        autocomplete="username"
                        class="mt-1.5 w-full rounded-md border px-3 py-2 text-sm"
                        :class="details.errors.email ? 'border-rose-400' : 'border-neutral-300'"
                    >
                    <p v-if="details.errors.email" class="mt-1.5 text-sm text-rose-600">{{ details.errors.email }}</p>
                </div>
            </div>

            <button
                type="submit"
                class="mt-5 rounded-md bg-admin-primary px-3 py-2 text-sm font-medium text-white hover:bg-admin-primary-dark disabled:cursor-not-allowed disabled:opacity-40"
                :disabled="details.processing"
            >
                Simpan detail
            </button>
        </form>

        <form class="rounded bg-admin-surface p-5 shadow-admin-card" @submit.prevent="savePassword">
            <h2 class="text-sm font-semibold">Ubah kata sandi</h2>
            <p class="mt-1 text-sm text-neutral-600">Mengubahnya akan mencabut seluruh token API di akun ini.</p>

            <div class="mt-4 space-y-4">
                <div>
                    <label for="current_password" class="block text-sm font-medium">Kata sandi saat ini</label>
                    <input
                        id="current_password"
                        v-model="password.current_password"
                        type="password"
                        autocomplete="current-password"
                        class="mt-1.5 w-full rounded-md border px-3 py-2 text-sm"
                        :class="password.errors.current_password ? 'border-rose-400' : 'border-neutral-300'"
                    >
                    <p
                        v-if="password.errors.current_password"
                        class="mt-1.5 text-sm text-rose-600"
                    >
                        {{ password.errors.current_password }}
                    </p>
                </div>

                <div>
                    <label for="new_password" class="block text-sm font-medium">Kata sandi baru</label>
                    <input
                        id="new_password"
                        v-model="password.password"
                        type="password"
                        autocomplete="new-password"
                        class="mt-1.5 w-full rounded-md border px-3 py-2 text-sm"
                        :class="password.errors.password ? 'border-rose-400' : 'border-neutral-300'"
                    >
                    <p v-if="password.errors.password" class="mt-1.5 text-sm text-rose-600">{{ password.errors.password }}</p>
                </div>

                <div>
                    <label for="new_password_confirmation" class="block text-sm font-medium">Konfirmasi kata sandi baru</label>
                    <input
                        id="new_password_confirmation"
                        v-model="password.password_confirmation"
                        type="password"
                        autocomplete="new-password"
                        class="mt-1.5 w-full rounded-md border px-3 py-2 text-sm"
                    >
                    <p
                        v-if="password.errors.password_confirmation"
                        class="mt-1.5 text-sm text-rose-600"
                    >
                        {{ password.errors.password_confirmation }}
                    </p>
                </div>
            </div>

            <button
                type="submit"
                class="mt-5 rounded-md bg-admin-primary px-3 py-2 text-sm font-medium text-white hover:bg-admin-primary-dark disabled:cursor-not-allowed disabled:opacity-40"
                :disabled="password.processing"
            >
                Ubah kata sandi
            </button>
        </form>

        <div class="rounded bg-admin-surface p-5 shadow-admin-card">
            <h2 class="text-sm font-semibold">Sesi</h2>
            <p class="mt-1 text-sm text-neutral-600">
                Keluar dari akun ini di peramban ini. Token API yang sudah diterbitkan tetap berlaku sampai dicabut atau kedaluwarsa.
            </p>

            <Form :action="routes.logout" method="post" class="mt-4 inline-block">
                <button
                    type="submit"
                    class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium hover:bg-neutral-100"
                >
                    Keluar
                </button>
            </Form>
        </div>

        <div class="rounded-lg border border-rose-200 bg-rose-50 p-5">
            <h2 class="text-sm font-semibold text-rose-900">Hapus akun</h2>
            <p class="mt-1 text-sm text-rose-800">
                Menghapus akun ini beserta seluruh token API miliknya. Tindakan ini tidak dapat dibatalkan.
            </p>

            <div class="mt-4 inline-block">
                <ConfirmDialog
                    :action="routes.accountDestroy"
                    prefix="Hapus akun "
                    :label="user.name"
                    question=" secara permanen?"
                    trigger-label="Hapus akun"
                    confirm-label="Hapus permanen"
                    trigger-class="rounded-md border border-rose-300 px-3 py-2 text-sm font-medium text-rose-700 hover:bg-rose-100"
                />
            </div>
        </div>
    </div>
</template>