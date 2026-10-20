<script setup>
/**
 * The documents filed with a sale.
 *
 * A note is text the page shows; a document is a file the page offers. So this is
 * a list of things with a name, a size and a link — and the link is signed and
 * expires, which is why it is built per request rather than kept.
 *
 * The list is passed in rather than fetched. A document is part of the sale, and
 * the sale's page is where somebody looks for what the sale was made of, so
 * asking for them separately would be a second round trip to answer a question the
 * page already answers.
 */
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';

import PanelSection from './PanelSection.vue';

const props = defineProps({
    documents: { type: Array, required: true },
    storeUrl: { type: String, required: true },
});

const failure = ref('');

const form = useForm({ file: null });

function choose(event) {
    failure.value = '';

    const file = event.target.files?.[0];

    // Reset so choosing the same file twice in a row is still a change.
    event.target.value = '';

    if (!file) {
        return;
    }

    form.file = file;
    form.post(props.storeUrl, {
        // The reply reloads the page, and a sale page is long. Staying where the
        // panel was matters more here than starting at the top.
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            form.file = null;
        },
        onError: (errors) => {
            // The server's own reason arrives in form.errors.file and is rendered
            // there — it names the bucket or the key that is wrong, and a general
            // sentence in its place would throw that away. This is only for the
            // case where the request never reached the server.
            if (!errors || !errors.file) {
                failure.value = 'Dokumen gagal diunggah.';
            }
        },
    });
}

function remove(document) {
    form.delete(document.destroyUrl, {
        preserveScroll: true,
    });
}
</script>

<template>
    <PanelSection title="Dokumen" :subtitle="`${documents.length} berkas.`">
        <ul v-if="documents.length" class="space-y-2">
            <li
                v-for="document in documents"
                :key="document.id"
                class="flex flex-wrap items-center justify-between gap-3 rounded border border-neutral-200 px-3 py-2"
            >
                <div class="min-w-0">
                    <a
                        v-if="document.url"
                        :href="document.url"
                        target="_blank"
                        rel="noopener"
                        class="truncate text-sm font-medium text-admin-primary hover:underline"
                    >
                        {{ document.name }}
                    </a>
                    <span v-else class="truncate text-sm font-medium text-neutral-400" :title="document.name">
                        {{ document.name }}
                    </span>

                    <p class="text-xs text-neutral-500">
                        {{ document.readableSize }}
                        <span v-if="document.uploadedBy"> &middot; {{ document.uploadedBy }}</span>
                        <span> &middot; {{ document.uploadedAt }}</span>
                    </p>
                </div>

                <button
                    type="button"
                    class="shrink-0 rounded px-2 py-1 text-xs text-neutral-500 hover:bg-neutral-100 hover:text-rose-600"
                    @click="remove(document)"
                >
                    Hapus
                </button>
            </li>
        </ul>

        <p v-else class="text-sm text-neutral-500">
            Belum ada dokumen yang dilampirkan pada penjualan ini.
        </p>

        <div class="mt-4">
            <label for="sale-document" class="block text-sm font-medium">Unggah dokumen</label>

            <input
                id="sale-document"
                type="file"
                accept=".pdf,.docx,.txt,application/pdf,text/plain,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                class="mt-1.5 block w-full text-sm text-neutral-600 file:mr-3 file:rounded file:border-0 file:bg-admin-primary file:px-3 file:py-2 file:text-sm file:font-medium file:text-white hover:file:bg-admin-primary-dark"
                @change="choose"
            >

            <p class="mt-1.5 text-xs text-neutral-500">
                PDF, DOCX, atau TXT. Berkasnya tidak bisa dibaca tanpa tautan yang berlaku singkat.
            </p>

            <p v-if="failure" class="mt-1.5 text-sm text-rose-600">{{ failure }}</p>
            <p v-if="form.errors.file" class="mt-1.5 text-sm text-rose-600">{{ form.errors.file }}</p>
        </div>
    </PanelSection>
</template>
