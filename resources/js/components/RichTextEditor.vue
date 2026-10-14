<script setup>
/**
 * A small rich-text editor for the note on a sale.
 *
 * Images are uploaded through the application's own endpoint rather than pasted
 * in as data URIs. That is a deliberate constraint: the note is stored as HTML,
 * so an inline base64 image would be re-saved in full on every keystroke-triggered
 * submit, and a browser does not have a reliable size limit on one. Sending the
 * picture to storage instead keeps the note small and lets the same endpoint
 * enforce mime type, size and filename.
 *
 * The upload is therefore async, and the note it produces is not valid until it
 * finishes. That is surfaced below the editor rather than hidden — an image that
 * silently failed to attach is worse than one that visibly did.
 *
 * Nothing here sanitises. The editor constrains what a well-behaved user
 * produces, which is not the same as making the stored value safe: pasted markup
 * arrives already formed and never passes through these buttons. The value is
 * sanitised server-side by SanitiseRichText, and this component assumes only that
 * what comes back from `note` has already been through it.
 */
import { computed, ref, onBeforeUnmount } from 'vue';
import { useEditor, EditorContent } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import Link from '@tiptap/extension-link';
import Image from '@tiptap/extension-image';

const props = defineProps({
    modelValue: { type: String, default: '' },
    error: { type: String, default: '' },
    uploadUrl: { type: String, required: true },
});

const emit = defineEmits(['update:modelValue']);

const fileInput = ref(null);
const uploading = ref(false);
const uploadError = ref('');

/**
 * The CSRF token, taken from the cookie rather than passed in as a prop.
 *
 * Laravel's VerifyCsrfToken accepts the encrypted XSRF-TOKEN cookie value on the
 * X-XSRF-TOKEN header, which is the header its own HTTP client sends. A raw
 * fetch does not, so it has to be added here — but reading the cookie that was
 * already set keeps a token off the page and out of a shared prop that every
 * other component would then be tempted to use.
 */
function csrfToken() {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

const editor = useEditor({
    content: props.modelValue,
    extensions: [
        StarterKit,
        Link.configure({
            openOnClick: false,
            autolink: true,
            // Not rel: the purifier adds noopener on render-side handling and a
            // pasted link is not a navigation the app controls. Kept minimal so
            // what the editor produces stays close to what is stored.
            protocols: ['http', 'https', 'mailto'],
        }),
        Image.configure({
            // Uploads are the only way an image gets in, so a pasted or dragged
            // data URI is not accepted here either.
            allowBase64: false,
            inline: false,
        }),
    ],
    onUpdate: ({ editor }) => emit('update:modelValue', editor.getHTML()),
});

const hasContent = computed(() => !editor.value?.isEmpty);

/**
 * Buttons reflect the state of the selection rather than the document, so
 * pressing one at the caret is reflected immediately.
 */
function toggle(command) {
    const chain = editor.value?.chain().focus();
    chain?.[command]?.().run();
}

const isActive = (name, attrs = {}) => editor.value?.isActive(name, attrs) ?? false;

function setLink() {
    const previous = editor.value?.getAttributes('link').href ?? '';
    const url = window.prompt('URL tautan', previous);

    // A null result is a cancel; an empty string is an intentional removal, which
    // is the only way to undo a link once it is applied.
    if (url === null) {
        return;
    }

    const chain = editor.value?.chain().focus();

    if (url === '') {
        chain?.extendMarkRange('link').unsetLink().run();

        return;
    }

    chain?.extendMarkRange('link').setLink({ href: url }).run();
}

async function uploadImage(file) {
    if (!file) {
        return;
    }

    uploading.value = true;
    uploadError.value = '';

    const body = new FormData();
    body.append('image', file);

    try {
        const response = await fetch(props.uploadUrl, {
            method: 'POST',
            headers: { 'X-XSRF-TOKEN': csrfToken(), Accept: 'application/json' },
            credentials: 'same-origin',
            body,
        });

        const payload = await response.json();

        if (!response.ok || !payload.url) {
            uploadError.value = payload.message ?? 'Gambar tidak dapat diunggah.';

            return;
        }

        editor.value?.chain().focus().setImage({ src: payload.url, alt: file.name }).run();
    } catch {
        // A network failure is indistinguishable from a server one here, and
        // saying which would be a guess. Say it failed.
        uploadError.value = 'Gambar tidak dapat diunggah.';
    } finally {
        uploading.value = false;
    }
}

function onFileChosen(event) {
    uploadImage(event.target.files?.[0]);

    // Reset so choosing the same file twice in a row still fires a change event.
    event.target.value = '';
}

function onPaste(event) {
    const file = [...(event.clipboardData?.files ?? [])].find((entry) => entry.type.startsWith('image/'));

    if (file) {
        event.preventDefault();
        uploadImage(file);
    }
}

// Tiptap keeps a ProseMirror view alive; unmounting without tearing it down
// leaves a detached editor listening on the document.
onBeforeUnmount(() => editor.value?.destroy());

defineExpose({ focus: () => editor.value?.commands.focus() });
</script>

<template>
    <div>
        <div class="overflow-hidden rounded border border-neutral-300 bg-admin-surface focus-within:border-admin-primary">
            <div v-if="editor" class="flex flex-wrap gap-1 border-b border-neutral-200 bg-neutral-50 p-1.5">
                <button
                    type="button"
                    title="Tebal"
                    :aria-pressed="isActive('bold')"
                    class="editor-button"
                    :class="{ 'editor-button-active': isActive('bold') }"
                    @click="toggle('toggleBold')"
                >
                    <strong>B</strong>
                </button>
                <button
                    type="button"
                    title="Miring"
                    :aria-pressed="isActive('italic')"
                    class="editor-button"
                    :class="{ 'editor-button-active': isActive('italic') }"
                    @click="toggle('toggleItalic')"
                >
                    <em>I</em>
                </button>
                <button
                    type="button"
                    title="Daftar berbutir"
                    :aria-pressed="isActive('bulletList')"
                    class="editor-button"
                    :class="{ 'editor-button-active': isActive('bulletList') }"
                    @click="toggle('toggleBulletList')"
                >
                    &bull;
                </button>
                <button
                    type="button"
                    title="Daftar bernomor"
                    :aria-pressed="isActive('orderedList')"
                    class="editor-button"
                    :class="{ 'editor-button-active': isActive('orderedList') }"
                    @click="toggle('toggleOrderedList')"
                >
                    1.
                </button>
                <button
                    type="button"
                    title="Tautan"
                    :aria-pressed="isActive('link')"
                    class="editor-button"
                    :class="{ 'editor-button-active': isActive('link') }"
                    @click="setLink"
                >
                    &#128279;
                </button>
                <button
                    type="button"
                    title="Hapus tautan"
                    class="editor-button"
                    @click="toggle('unsetLink')"
                >
                    &#10006;
                </button>

                <span class="mx-1 w-px self-stretch bg-neutral-200"></span>

                <button
                    type="button"
                    title="Tambah gambar"
                    class="editor-button"
                    :disabled="uploading"
                    @click="fileInput?.click()"
                >
                    {{ uploading ? 'Mengunggah...' : 'Gambar' }}
                </button>
                <input
                    ref="fileInput"
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    class="sr-only"
                    @change="onFileChosen"
                >
            </div>

            <EditorContent
                :editor="editor"
                class="prose-editor min-h-32 px-3 py-2 text-sm focus:outline-none"
                @paste="onPaste"
            />
        </div>

        <p v-if="uploading" class="mt-1.5 text-sm text-neutral-500">Mengunggah gambar...</p>
        <p v-if="uploadError" class="mt-1.5 text-sm text-rose-600">{{ uploadError }}</p>
        <p v-else-if="error" class="mt-1.5 text-sm text-rose-600">{{ error }}</p>
        <p v-if="!hasContent && !uploading" class="mt-1.5 text-sm text-neutral-400">
            Opsional. Tempel gambar, atau gunakan tombol di atas.
        </p>
    </div>
</template>

<style scoped>
.editor-button {
    border-radius: 0.25rem;
    padding: 0.25rem 0.5rem;
    font-size: 0.8125rem;
    line-height: 1.25rem;
    color: #404040;
}

.editor-button:hover:not(:disabled) {
    background: #e5e5e5;
}

.editor-button:disabled {
    color: #a3a3a3;
    cursor: progress;
}

.editor-button-active {
    background: #000;
    color: #fff;
}
</style>