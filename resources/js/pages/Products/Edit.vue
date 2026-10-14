<script setup>
/**
 * Editing a product.
 *
 * A partial update, so any field left out keeps its current value. The form
 * submits every field, but the underlying rules are UpdateProductRequest's and
 * behave the same way the API's do.
 *
 * The update URL comes from the controller as a prop rather than being assembled
 * here, because route('products.update', $product) is a route definition and
 * rebuilding it in JavaScript would put it in two files.
 */
import { Head, Link, useForm } from '@inertiajs/vue3';

import AdminLayout from '../../layouts/AdminLayout.vue';
import ProductFields from '../../components/ProductFields.vue';
import { useRoutes } from '../../shared/routes.js';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    product: { type: Object, required: true },
    updateUrl: { type: String, required: true },
});

const routes = useRoutes();

const form = useForm({
    name: props.product.name,
    description: props.product.description ?? '',
    price: props.product.price,
    stock: props.product.stock,
});

function submit() {
    form.patch(props.updateUrl);
}
</script>

<template>
    <Head :title="`Ubah ${product.name}`" />

    <h1 class="text-lg font-semibold tracking-tight">Ubah Produk</h1>

    <form class="mt-6" @submit.prevent="submit">
        <ProductFields :form="form" />

        <div class="mt-6 flex items-center gap-2">
            <button
                type="submit"
                class="rounded-md bg-admin-primary px-3 py-2 text-sm font-medium text-white hover:bg-admin-primary-dark disabled:cursor-not-allowed disabled:opacity-40"
                :disabled="form.processing"
            >
                Simpan perubahan
            </button>

            <Link
                :href="routes.products"
                class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium hover:bg-neutral-100"
            >
                Batal
            </Link>
        </div>
    </form>
</template>