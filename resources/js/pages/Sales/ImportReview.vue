<script setup>
/**
 * The draft, checked by a person before it becomes sales.
 *
 * This screen is the whole reason the import was split in two. When the columns
 * come from a table they can be trusted to the byte; when they come from a model
 * reading a document they cannot, and the only thing that makes an inferred value
 * safe to write is someone having looked at it. So the cells are editable here,
 * and nothing has been stored yet — navigating away discards the draft and costs
 * nothing but the extraction.
 *
 * The customer and the date are editable too, and prefilled from the previous
 * screen. They are per-order facts a list of rows cannot carry, so they are asked
 * for once; letting them be corrected here rather than sending the person back to
 * re-upload a large document is the difference between a fixable mistake and a
 * wasted upload.
 *
 * The save button posts the rows back and nothing else. The server re-validates
 * every cell from scratch — names against the catalogue, quantities and prices
 * against the same rules the manual form uses — so this screen at worst proposes
 * a draft, and a tampered one is refused exactly like a wrong document.
 */
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

import AdminLayout from '../../layouts/AdminLayout.vue';
import PanelSection from '../../components/PanelSection.vue';
import { useRoutes } from '../../shared/routes.js';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    /** The rows as they were read, each with the contract columns in `cells`. */
    draft: { type: Array, required: true },
    /** 'table' when the document was read exactly, 'document' when a model read it. */
    origin: { type: String, required: true },
    customers: { type: Array, required: true },
    columns: { type: Array, required: true },
    maxRows: { type: Number, required: true },
    context: { type: Object, required: true },
});

const routes = useRoutes();

const LABELS = {
    sales: 'Sales',
    kota: 'Kota',
    produk: 'Produk',
    jumlah: 'Jumlah',
    harga: 'Harga',
};

function label(column) {
    return LABELS[column] ?? column;
}

// Shaped as the server validates them — rows of cells, with no row number of our
// own. The number is assigned from the position on the way in, so the row a
// refusal names is the row counted here rather than one the browser supplied.
const form = useForm({
    origin: props.origin,
    customer_id: props.context.customer_id,
    sold_at: props.context.sold_at ?? '',
    rows: props.draft.map((row) => ({ cells: { ...row.cells } })),
});

function submit() {
    form.post(routes.salesImportStore);
}

const fromModel = computed(() => props.origin === 'document');

/**
 * The refused rows as a list, whatever shape the error bag handed over.
 *
 * Normalising once here keeps the v-for out of the business of guessing whether
 * it was handed a list or a single message.
 */
const fileErrors = computed(() => {
    const errors = form.errors.file;

    if (!errors) {
        return [];
    }

    return Array.isArray(errors) ? errors : [errors];
});
</script>

<template>
    <Head title="Periksa Draf Impor" />

    <h1 class="text-lg font-semibold tracking-tight">Periksa Draf Impor</h1>
    <p class="mt-0.5 text-sm text-neutral-500">
        {{ form.rows.length }} baris dibaca. Belum ada yang tersimpan.
    </p>

    <!--
        Two different warnings, because the two paths carry different risk. A table
        was read exactly and only needs a glance; a model's reading of a document
        can be plausible and wrong on any cell, and saying so is the only thing
        standing between that and an invoice nobody agreed to.
    -->
    <div
        v-if="fromModel"
        class="mt-5 rounded border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900"
    >
        <p class="font-medium">Draf ini dibaca AI dari dokumen.</p>
        <p class="mt-1">
            Periksa setiap baris — nama, produk, jumlah dan harga — sebelum menyimpan. Yang tidak
            dikenali akan menolak seluruh baris.
        </p>
    </div>

    <div
        v-else
        class="mt-5 rounded border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900"
    >
        <p class="font-medium">Dibaca langsung dari tabel.</p>
        <p class="mt-1">
            Nilainya diambil apa adanya dari berkas, tanpa AI. Tidak ada yang tersimpan sebelum
            kamu menekan simpan.
        </p>
    </div>

    <form class="mt-6 space-y-6" @submit.prevent="submit">
        <PanelSection title="Pelanggan dan tanggal" subtitle="Dipakai untuk seluruh baris.">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="customer_id" class="block text-sm font-medium">Pelanggan</label>
                    <select
                        id="customer_id"
                        v-model="form.customer_id"
                        required
                        class="mt-1.5 w-full rounded border bg-admin-surface px-3 py-2 text-sm focus:border-admin-primary focus:outline-none"
                        :class="form.errors.customer_id ? 'border-rose-400' : 'border-neutral-300'"
                    >
                        <option value="" disabled>Pilih pelanggan</option>
                        <option v-for="customer in customers" :key="customer.id" :value="customer.id">
                            {{ customer.name }}
                        </option>
                    </select>
                    <p v-if="form.errors.customer_id" class="mt-1.5 text-sm text-rose-600">
                        {{ form.errors.customer_id }}
                    </p>
                </div>

                <div>
                    <label for="sold_at" class="block text-sm font-medium">Tanggal penjualan</label>
                    <input
                        id="sold_at"
                        v-model="form.sold_at"
                        type="date"
                        class="mt-1.5 w-full rounded border bg-admin-surface px-3 py-2 text-sm focus:border-admin-primary focus:outline-none"
                        :class="form.errors.sold_at ? 'border-rose-400' : 'border-neutral-300'"
                    >
                    <p v-if="form.errors.sold_at" class="mt-1.5 text-sm text-rose-600">
                        {{ form.errors.sold_at }}
                    </p>
                </div>
            </div>
        </PanelSection>

        <PanelSection
            title="Baris"
            subtitle="Kolom yang tidak ada di data ini akan menolak seluruh baris saat disimpan."
        >
            <div class="overflow-x-auto">
                <table class="w-full min-w-[46rem] text-left text-sm">
                    <thead class="border-b border-neutral-200 text-xs uppercase tracking-wide text-neutral-500">
                        <tr>
                            <th scope="col" class="w-10 py-2 font-medium">#</th>
                            <th
                                v-for="column in columns"
                                :key="column"
                                scope="col"
                                class="py-2 pr-2 font-medium"
                            >
                                {{ label(column) }}
                            </th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-neutral-100">
                        <tr v-for="(row, index) in form.rows" :key="index">
                            <td class="py-1.5 pr-2 text-neutral-400">{{ index + 1 }}</td>
                            <td v-for="column in columns" :key="column" class="py-1.5 pr-2">
                                <label class="sr-only" :for="`cell-${index}-${column}`">
                                    {{ label(column) }} baris {{ index + 1 }}
                                </label>
                                <input
                                    :id="`cell-${index}-${column}`"
                                    v-model="row.cells[column]"
                                    type="text"
                                    class="w-full rounded border border-neutral-300 px-2 py-1.5 text-sm focus:border-admin-primary focus:outline-none"
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="mt-3 text-sm text-neutral-500">
                Maksimal {{ maxRows }} baris per berkas.
            </p>
        </PanelSection>

        <div v-if="fileErrors.length" class="rounded border border-rose-200 bg-rose-50 p-4">
            <h2 class="text-sm font-medium text-rose-800">
                Draf ditolak. Tidak ada penjualan yang disimpan.
            </h2>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-rose-700">
                <li v-for="(message, index) in fileErrors" :key="index">{{ message }}</li>
            </ul>
        </div>

        <div class="flex items-center gap-2">
            <button
                type="submit"
                class="rounded-md bg-admin-primary px-3 py-2 text-sm font-medium text-white hover:bg-admin-primary-dark disabled:cursor-not-allowed disabled:opacity-40"
                :disabled="form.processing"
            >
                Simpan {{ form.rows.length }} penjualan
            </button>

            <Link
                :href="routes.salesImport"
                class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium hover:bg-neutral-100"
            >
                Kembali
            </Link>

            <Link
                :href="routes.sales"
                class="px-2 py-2 text-sm text-neutral-500 hover:underline"
            >
                Batal
            </Link>
        </div>
    </form>
</template>
