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
import ProductImages from '../../components/ProductImages.vue';
import { useRoutes } from '../../shared/routes.js';
import { usePageCache } from '../../shared/pageCache.js';

const { invalidate } = usePageCache();

defineOptions({ layout: AdminLayout });

const props = defineProps({
    product: { type: Object, required: true },
    updateUrl: { type: String, required: true },
    /** The gallery as stored: `{id, path, alt_text, url}` per row, in order. */
    images: { type: Array, required: true },
});

const routes = useRoutes();

const form = useForm({
    name: props.product.name,
    description: props.product.description ?? '',
    price: props.product.price,
    stock: props.product.stock,

    // The rows the page was given, posted back as they are. The server replaces its
    // gallery with this list, so leaving it alone sends back exactly what is already
    // there and changing it sends back exactly what should be.
    images: props.images,
});

function submit() {
    form.patch(props.updateUrl, {
        // Updating a product changes both the list and this edit page, so both
        // cached entries are stale. Invalidate them so the next visit fetches fresh
        // data.
        onSuccess: () => {
            invalidate(routes.products);
            invalidate(`/products/${props.product.id}/edit`);
        },
    });
}
</script>

<template>
    <Head :title="`Ubah ${product.name}`" />

    <h1 class="text-lg font-semibold tracking-tight">Ubah Produk</h1>

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