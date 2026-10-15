<script setup>
/**
 * Uploading a document to import sales from.
 *
 * This screen only reads the file — it writes nothing. What comes back is the
 * draft, on the next screen, and only a draft a person confirms becomes sales.
 *
 * The rules the page states are the rules ImportSales enforces, and the column
 * names come from the server rather than from this markup: config/imports.php is
 * the single definition of the contract, so a change to it changes the
 * instruction the person is reading instead of leaving the two to disagree.
 *
 * forceFormData because a File object cannot survive JSON serialisation, which is
 * what Inertia posts by default. Without it the file arrives as the string
 * "[object File]" and the endpoint reports a missing file.
 */
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

import AdminLayout from '../../layouts/AdminLayout.vue';
import PanelSection from '../../components/PanelSection.vue';
import { useRoutes } from '../../shared/routes.js';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    customers: { type: Array, required: true },
    /** The column names the tabular path requires, from config/imports.php. */
    columns: { type: Array, required: true },
    /** The row ceiling the import enforces, from the same file. */
    maxRows: { type: Number, required: true },
    /** The character ceiling for a document read by the model, from the same file. */
    textLimit: { type: Number, required: true },
});

const routes = useRoutes();

const form = useForm({
    file: null,
    customer_id: '',
    sold_at: new Date().toISOString().slice(0, 10),
});

function submit() {
    form.post(routes.salesImportExtract, {
        forceFormData: true,
    });
}

/**
 * The refused file as a list, whatever shape the error bag handed over.
 *
 * Laravel's message bag holds a list per key, but a single-message field can
 * arrive as a bare string, and a v-for over a string walks it character by
 * character. Normalising once here keeps that out of the template.
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
    <Head title="Impor Penjualan" />

    <h1 class="text-lg font-semibold tracking-tight">Impor Penjualan</h1>
    <p class="mt-0.5 text-sm text-neutral-500">
        Satu baris dokumen menjadi satu penjualan dengan satu item.
    </p>

    <form class="mt-6 space-y-6" @submit.prevent="submit">
        <PanelSection title="Dua cara membaca dokumen" subtitle="Kamu akan memeriksa hasilnya sebelum disimpan.">
            <div class="space-y-4 text-sm">
                <div>
                    <p class="font-medium text-neutral-700">Tabel — dibaca persis</p>
                    <p class="mt-1 text-neutral-600">
                        Berkas <span class="font-mono">.txt</span> atau
                        <span class="font-mono">.xlsx</span> yang baris pertamanya memuat kolom
                        <span class="font-mono">{{ columns.join(', ') }}</span>
                        dibaca apa adanya, tanpa AI, sama seperti impor sebelumnya.
                    </p>
                </div>

                <div>
                    <p class="font-medium text-neutral-700">Dokumen — dibaca AI</p>
                    <p class="mt-1 text-neutral-600">
                        Selain itu — <span class="font-mono">.docx</span>,
                        <span class="font-mono">.pdf</span>, atau
                        <span class="font-mono">.txt</span> berbentuk laporan — isinya dibaca AI
                        menjadi draf. Draf itu <strong>wajib kamu periksa</strong>: tidak ada yang
                        tersimpan sebelum kamu menekan simpan.
                    </p>
                </div>

                <dl>
                    <dt class="font-medium text-neutral-700">Contoh baris tabel</dt>
                    <dd class="mt-1 font-mono text-neutral-600">
                        {{ columns.join(',') }}<br>
                        Andi,Jakarta,Helm ORIGINAL,2,15000
                    </dd>
                </dl>

                <ul class="list-disc space-y-1 pl-5 text-neutral-600">
                    <li>
                        Nama sales, kota dan produk harus sama persis dengan yang ada di data ini.
                        Yang tidak ditemukan atau yang cocok dengan lebih dari satu akan menolak
                        seluruh berkas.
                    </li>
                    <li>
                        Angka tanpa pemisah ribuan: tulis <span class="font-mono">15000</span>,
                        bukan <span class="font-mono">15.000</span>.
                    </li>
                    <li>
                        PDF hasil scan belum bisa dibaca — isinya gambar, bukan teks.
                    </li>
                    <li>
                        <span class="font-mono">.csv</span>,
                        <span class="font-mono">.doc</span> dan
                        <span class="font-mono">.xls</span> tidak didukung. Simpan ulang sebagai
                        <span class="font-mono">.txt</span>/<span class="font-mono">.xlsx</span> atau
                        <span class="font-mono">.docx</span>.
                    </li>
                </ul>
            </div>
        </PanelSection>

        <PanelSection title="Berkas">
            <div>
                <label for="import-file" class="block text-sm font-medium">Berkas dokumen</label>
                <input
                    id="import-file"
                    type="file"
                    accept=".txt,.xlsx,.docx,.pdf,text/plain,application/pdf"
                    required
                    class="mt-1.5 block w-full text-sm text-neutral-600 file:mr-3 file:rounded file:border-0 file:bg-admin-primary file:px-3 file:py-2 file:text-sm file:font-medium file:text-white hover:file:bg-admin-primary-dark"
                    @change="form.file = $event.target.files[0] ?? null"
                >
                <p class="mt-1.5 text-sm text-neutral-500">
                    Maksimal {{ maxRows }} baris per berkas. Dokumen yang dibaca AI maksimal
                    {{ textLimit }} karakter.
                </p>
            </div>
        </PanelSection>

        <PanelSection
            title="Pelanggan dan tanggal"
            subtitle="Dipakai untuk seluruh baris, karena tidak ada di dalam dokumen."
        >
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

        <!--
            One message per refusal, rather than one summary line. ImportSales
            reports every problem it found in one pass precisely so that fixing a
            document does not mean re-reading the whole thing after each correction.
        -->
        <div v-if="fileErrors.length" class="rounded border border-rose-200 bg-rose-50 p-4">
            <h2 class="text-sm font-medium text-rose-800">
                Berkas ditolak. Tidak ada penjualan yang diimpor.
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
                Baca dokumen
            </button>

            <Link
                :href="routes.sales"
                class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium hover:bg-neutral-100"
            >
                Batal
            </Link>
        </div>
    </form>
</template>
