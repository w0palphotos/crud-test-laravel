<script setup>
/**
 * Recording a sale.
 *
 * Thin on purpose: the fields, the running totals and the check for the three
 * selects the browser cannot enforce all live in SaleEditor, which the detail page
 * also renders when it is being edited. What is left here is the one thing the two
 * genuinely do differently — this form creates a sale, that one amends one — and
 * so posts somewhere else.
 *
 * The running totals the editor shows are never sent. SaleTotals recomputes every
 * one of them from the quantities and prices, because a total that arrived from a
 * browser is not a total anyone should store.
 */
import { Head, Link, useForm } from '@inertiajs/vue3';

import AdminLayout from '../../layouts/AdminLayout.vue';
import SaleEditor from '../../components/SaleEditor.vue';
import { blankLine, ensureDetailChosen, localDateTime } from '../../shared/saleForm.js';
import { useRoutes } from '../../shared/routes.js';

defineOptions({ layout: AdminLayout });

defineProps({
    customers: { type: Array, required: true },
    salespeople: { type: Array, required: true },
    cities: { type: Array, required: true },
    tags: { type: Array, required: true },
    products: { type: Array, required: true },
});

const routes = useRoutes();

const form = useForm({
    customer_id: '',
    salesperson_id: '',
    city_id: '',
    sold_at: localDateTime(),
    discount_total: '',
    tax_total: '',
    note: '',
    status: '',
    items: [blankLine()],
    tags: [],
    documents: [],
});

function submit() {
    if (! ensureDetailChosen(form)) {
        return;
    }

    form.post(routes.salesStore);
}
</script>

<template>
    <Head title="Penjualan Baru" />

    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="text-lg font-semibold tracking-tight">Penjualan Baru</h1>
            <p class="mt-1 text-sm text-neutral-500">
                Catat apa yang terjual, oleh siapa, dan ke pelanggan mana.
            </p>
        </div>

        <Link
            :href="routes.sales"
            class="shrink-0 rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium hover:bg-neutral-100"
        >
            Kembali ke penjualan
        </Link>
    </div>

    <form class="mt-6" @submit.prevent="submit">
        <SaleEditor
            :form="form"
            :customers="customers"
            :salespeople="salespeople"
            :cities="cities"
            :tags="tags"
            :products="products"
            submit-label="Simpan penjualan"
        />
    </form>
</template>