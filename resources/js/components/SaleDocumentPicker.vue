<script setup>
/**
 * The documents chosen for a sale that has not been made yet.
 *
 * The same shape as the product's gallery: a file is written the moment it is
 * chosen, and what the form carries is the description of the file rather than
 * the file itself. It has to be that way round — a sale has to exist before a
 * document can belong to it, and the sale is made when the form is submitted.
 *
 * So the file is already on the disk when the form is sent, and the sale is
 * created with the documents in the same request. The link is not part of this:
 * the bucket is private and a link to a document is signed when the sale's page
 * is read, not before.
 */
import { ref } from 'vue';

const props = defineProps({
    /** The chosen documents, as descriptions of files already on the disk. */
    modelValue: { type: Array, required: true },
    uploadUrl: { type: String, required: true },
    error: { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue']);

const fileInput = ref(null);
const failure = ref('');
const uploading = ref(false);

/**
 * The human size of a file, for a line that says what it is.
 */
function readableSize(bytes) {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1024 * 1024) {
        return `${Math.round((bytes / 1024) * 10) / 10} KB`;
    }

    return `${Math.round((bytes / (1024 * 1024)) * 10) / 10} MB`;
}

function choose(event) {
    failure.value = '';

    const file = event.target.files?.[0];

    // Reset so choosing the same file twice in a row is still a change.
    event.target.value = '';

    if (!file) {
        return;
    }

    uploading.value = true;

    const body = new FormData();
    body.append('file', file);

    fetch(props.uploadUrl, {
        method: 'POST',
        headers: { 'X-XSRF-TOKEN': xsrfToken(), Accept: 'application/json' },
        credentials: 'same-origin',
        body,
    })
        .then(async (response) => {
            const payload = await response.json().catch(() => ({}));

            if (!response.ok) {
                failure.value = payload.message ?? 'Dokumen gagal diunggah.';

                return;
            }

            emit('update:modelValue', [
                ...props.modelValue,
                {
                    path: payload.path,
                    original_name: payload.original_name,
                    mime_type: payload.mime_type,
                    size: payload.size,
                },
            ]);
        })
        .catch(() => {
            failure.value = 'Dokumen gagal diunggah.';
        })
        .finally(() => {
            uploading.value = false;
        });
}

function remove(index) {
    const documents = [...props.modelValue];
    documents.splice(index, 1);

    emit('update:modelValue', documents);
}

/**
 * The token the application already set, read from the cookie it set.
 *
 * Taken from the cookie rather than passed in, because the cookie is already on
 * the page and a token handed around as a prop is a token somebody will end up
 * putting in a file.
 */
function xsrfToken() {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}
</script>

<template>
    <div>
        <label for="sale-document" class="block text-sm font-medium">Dokumen</label>

        <input
            id="sale-document"
            ref="fileInput"
            type="file"
            accept=".pdf,.docx,.txt,application/pdf,text/plain,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
            class="mt-1.5 block w-full text-sm text-neutral-600 file:mr-3 file:rounded file:border-0 file:bg-admin-primary file:px-3 file:py-2 file:text-sm file:font-medium file:text-white hover:file:bg-admin-primary-dark"
            :disabled="uploading"
            @change="choose"
        >

        <p class="mt-1.5 text-xs text-neutral-500">
            PDF, DOCX, atau TXT. Berkasnya dilampirkan ke penjualan ini.
        </p>

        <p v-if="uploading" class="mt-1.5 text-sm text-neutral-500">Mengunggah dokumen…</p>
        <p v-if="failure" class="mt-1.5 text-sm text-rose-600">{{ failure }}</p>
        <p v-if="error" class="mt-1.5 text-sm text-rose-600">{{ error }}</p>

        <ul v-if="modelValue.length" class="mt-3 space-y-2">
            <li
                v-for="(document, index) in modelValue"
                :key="document.path"
                class="flex items-center justify-between gap-3 rounded border border-neutral-200 px-3 py-2"
            >
                <div class="min-w-0">
                    <p class="truncate text-sm font-medium">{{ document.original_name }}</p>
                    <p class="text-xs text-neutral-500">{{ readableSize(document.size) }}</p>
                </div>

                <button
                    type="button"
                    class="shrink-0 rounded px-2 py-1 text-xs text-neutral-500 hover:bg-neutral-100 hover:text-rose-600"
                    @click="remove(index)"
                >
                    Hapus
                </button>
            </li>
        </ul>
    </div>
</template>
