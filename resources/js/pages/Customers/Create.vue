<script setup>
/**
 * Adding a customer and their commercial profile in one form.
 *
 * The schema keeps a customer and their profile in two tables, because the two have
 * different lifecycles. The form does not: whoever is typing a new customer has
 * their name, their city and their payment terms in front of them at once, and
 * asking them to save once and then fill in the profile afterwards is how a
 * customer ends up on the books with nobody having worked out what they may owe.
 *
 * So this is one form posting to one request that writes both rows. Two requests
 * would mean two things that can fail apart and a customer left behind with no
 * profile after the second one refused.
 *
 * Both field sets bind to the same form rather than to one each, which is what
 * keeps a single error bag: a refusal on `credit_limit` has to appear next to the
 * credit limit box, and with two forms the profile's errors would belong to an
 * object the page never submitted from.
 *
 * The fields and the rules are StoreCustomerRequest's, unchanged: nothing about
 * validation moved to the browser, because a browser is not a trust boundary.
 */
import { Head, Link, useForm } from '@inertiajs/vue3';

import AdminLayout from '../../layouts/AdminLayout.vue';
import CustomerFields from '../../components/CustomerFields.vue';
import CustomerProfileFields from '../../components/CustomerProfileFields.vue';
import { useRoutes } from '../../shared/routes.js';

defineOptions({ layout: AdminLayout });

defineProps({
    /** The cities a profile may point at. */
    cities: { type: Array, required: true },
});

const routes = useRoutes();

const form = useForm({
    name: '',
    email: '',
    phone: '',
    city_id: '',
    address: '',
    tax_id: '',
    credit_limit: '',
    payment_terms: '',
});

function submit() {
    form.post(routes.customersStore);
}
</script>

<template>
    <Head title="Pelanggan Baru" />

    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="text-lg font-semibold tracking-tight">Pelanggan Baru</h1>
            <p class="mt-1 text-sm text-neutral-500">
                Pelanggan dan profil komersialnya disimpan bersama.
            </p>
        </div>

        <Link
            :href="routes.customers"
            class="shrink-0 rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium hover:bg-neutral-100"
        >
            Kembali
        </Link>
    </div>

    <form class="mt-6 grid max-w-3xl gap-6" @submit.prevent="submit">
        <div class="rounded bg-admin-surface p-5 shadow-admin-card">
            <h2 class="text-sm font-semibold">Data pelanggan</h2>

            <div class="mt-4">
                <CustomerFields :form="form" />
            </div>
        </div>

        <div class="rounded bg-admin-surface p-5 shadow-admin-card">
            <h2 class="text-sm font-semibold">Profil komersial</h2>

            <div class="mt-4">
                <CustomerProfileFields :form="form" :cities="cities" />
            </div>
        </div>

        <div class="flex items-center gap-2">
            <button
                type="submit"
                class="rounded-md bg-admin-primary px-3 py-2 text-sm font-medium text-white hover:bg-admin-primary-dark disabled:cursor-not-allowed disabled:opacity-40"
                :disabled="form.processing"
            >
                Buat pelanggan
            </button>

            <Link
                :href="routes.customers"
                class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium hover:bg-neutral-100"
            >
                Batal
            </Link>
        </div>
    </form>
</template>