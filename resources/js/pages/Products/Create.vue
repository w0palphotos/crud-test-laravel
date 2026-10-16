<script setup>
/**
 * Creating a product.
 *
 * The rules are StoreProductRequest's, unchanged: Inertia posts to the same
 * controller, the same FormRequest validates it, and the errors come back as
 * props. Nothing about the validation moved to the browser, because a browser is
 * not a trust boundary.
 */
import { Head, Link, useForm } from '@inertiajs/vue3';

import AdminLayout from '../../layouts/AdminLayout.vue';
import ProductFields from '../../components/ProductFields.vue';
import ProductImages from '../../components/ProductImages.vue';
import { useRoutes } from '../../shared/routes.js';

defineOptions({ layout: AdminLayout });

const routes = useRoutes();

const form = useForm({
    name: '',
    description: '',
    price: '',
    stock: 0,

    // Empty, and posted with the product rather than through an endpoint of its own:
    // the gallery is part of what this form means, and the server replaces its rows
    // with whatever list arrives.
    images: [],
});

function submit() {
    form.post(routes.productsStore);
}
</script>

<template>
    <Head title="Produk Baru" />

    <h1 class="text-lg font-semibold tracking-tight">Produk Baru</h1>

    <form class="mt-6" @submit.prevent="submit">
        <ProductFields :form="form" />

        <div class="mt-6">
            <ProductImages
                v-model="form.images"
                :upload-url="routes.uploadsStore"
                :errors="form.errors"
            />
        </div>

        <div class="mt-6 flex items-center gap-2">
            <button
                type="submit"
                class="rounded-md bg-admin-primary px-3 py-2 text-sm font-medium text-white hover:bg-admin-primary-dark disabled:cursor-not-allowed disabled:opacity-40"
                :disabled="form.processing"
            >
                Buat produk
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