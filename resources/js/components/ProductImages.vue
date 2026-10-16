<script setup>
/**
 * A product's gallery: choose files, describe them, put them in order, drop them.
 *
 * Files are uploaded as they are chosen rather than when the form is submitted.
 * The upload endpoint answers with the URL the browser needs to show a thumbnail,
 * and a gallery nobody can look at is not a gallery — so the picture appears while
 * the form is still open. What the form then carries is the list of what should
 * exist and in what order, and the server replaces its rows with exactly that.
 *
 * Order is moved with buttons rather than dragged. Dragging needs a library, and
 * this application has no drag-and-drop anywhere yet; two arrows are enough for a
 * list short enough to fit on one page, and they cost nothing to keep working.
 *
 * "Main picture" is the first row, and that is a fact about the list rather than a
 * flag on a row: the gallery is an order and the catalogue shows the head of it.
 * So choosing a new main picture is the same operation as moving a row, and the
 * buttons are stated as what they do — move this one to the top — rather than as a
 * switch that sets something that is not stored.
 *
 * The rows are the caller's form state, not a copy of it, so an alt text typed here
 * is written straight into `form.images` — one source of truth, and no watcher to
 * keep two in step. Structural changes (add, move, remove) emit a fresh array
 * instead, because those are the operations whose result the parent has to see.
 */
import { computed, ref } from 'vue';

import Icon from './Icon.vue';

const props = defineProps({
    /** Rows of `{ id?, path, alt_text, url }` — the shape the server validates. */
    modelValue: { type: Array, required: true },
    /** Where a chosen file is posted. The endpoint answers `{path, url, name}`. */
    uploadUrl: { type: String, required: true },
    /** The surrounding form's error bag, so a refusal lands on the gallery too. */
    errors: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['update:modelValue']);

const uploading = ref(0);
const failure = ref('');

const busy = computed(() => uploading.value > 0);

/**
 * Laravel's own XSRF cookie, sent back as the header it expects.
 *
 * The same reading RichTextEditor does, and for the same reason: the token is
 * already on the page, so nothing here has to be handed one from a prop.
 */
function csrfToken() {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * The first thing the server said about the gallery, if anything.
 *
 * Keys arrive per row — `images.0.path` — because that is what makes the refusal
 * useful in a log. A person reading the page needs the sentence, not the index.
 */
const refused = computed(() => {
    const key = Object.keys(props.errors).find((name) => name.startsWith('images'));

    return key ? props.errors[key] : '';
});

async function choose(event) {
    const files = Array.from(event.target.files ?? []);

    // Cleared before the first upload, so choosing the same file twice in a row
    // still counts as a change — otherwise a failed upload could not be retried
    // with the very file that failed.
    event.target.value = '';

    failure.value = '';
    uploading.value += files.length;

    // One request per file, because the endpoint takes one. The throttle on it
    // (30 a minute) is what stops a mis-click on a folder from becoming a burst.
    for (const file of files) {
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

            if (! response.ok || ! payload.path) {
                failure.value = payload.message ?? 'Gambar tidak dapat diunggah.';

                continue;
            }

            emit('update:modelValue', [...props.modelValue, {
                path: payload.path,
                url: payload.url,
                alt_text: '',
            }]);
        } catch {
            // A network failure and a server failure are indistinguishable from
            // here, and naming one would be a guess. Say it failed.
            failure.value = 'Gambar tidak dapat diunggah.';
        } finally {
            uploading.value -= 1;
        }
    }
}

function move(index, by) {
    const to = index + by;

    if (to < 0 || to >= props.modelValue.length) {
        return;
    }

    const rows = [...props.modelValue];

    [rows[index], rows[to]] = [rows[to], rows[index]];

    emit('update:modelValue', rows);
}

function drop(index) {
    emit('update:modelValue', props.modelValue.filter((row, at) => at !== index));
}

/**
 * Make this row the first one, which is what "main picture" means.
 *
 * A move rather than a swap, and the gap it leaves is closed afterwards. Moving
 * row 3 to the front makes it the head of the list; swapping it with the head
 * would leave the old head in position 3 and the order the desk arranged would
 * not be the order they asked for.
 */
function promote(index) {
    if (index === 0) {
        return;
    }

    const rows = [...props.modelValue];
    const [row] = rows.splice(index, 1);

    emit('update:modelValue', [row, ...rows]);
}
</script>

<template>
    <div>
        <label for="product-images" class="block text-sm font-medium">Gambar</label>

        <input
            id="product-images"
            type="file"
            accept="image/jpeg,image/png,image/webp"
            multiple
            class="mt-1.5 block w-full text-sm text-neutral-600 file:mr-3 file:rounded file:border-0 file:bg-admin-primary file:px-3 file:py-2 file:text-sm file:font-medium file:text-white hover:file:bg-admin-primary-dark"
            @change="choose"
        >

        <p class="mt-1.5 text-sm text-neutral-500">
            JPG, PNG, atau WebP, maksimal 2 MB per gambar. Gambar pertama dipakai sebagai gambar utama.
        </p>

        <p v-if="busy" class="mt-1.5 text-sm text-neutral-500">
            Mengunggah {{ uploading }} gambar…
        </p>

        <p v-if="failure" class="mt-1.5 text-sm text-rose-600">{{ failure }}</p>

        <p v-if="refused" class="mt-1.5 text-sm text-rose-600">{{ refused }}</p>

        <ul v-if="modelValue.length" class="mt-3 space-y-2">
            <li
                v-for="(row, index) in modelValue"
                :key="row.id ?? row.path"
                class="flex items-start gap-3 rounded border border-neutral-200 p-2"
            >
                <!--
                    alt from the typed caption, because this thumbnail is a picture of
                    the product in a form — an empty alt here would read as
                    decoration, which is the opposite of what it is.
                -->
                <img
                    :src="row.url"
                    :alt="row.alt_text || 'Gambar produk'"
                    class="h-16 w-16 shrink-0 rounded object-cover"
                >

                <div class="min-w-0 flex-1">
                    <label :for="`image-alt-${index}`" class="sr-only">
                        Teks alternatif gambar {{ index + 1 }}
                    </label>
                    <input
                        :id="`image-alt-${index}`"
                        v-model="row.alt_text"
                        type="text"
                        maxlength="255"
                        placeholder="Teks alternatif (opsional)"
                        class="w-full rounded border border-neutral-300 bg-admin-surface px-2 py-1.5 text-sm focus:border-admin-primary focus:outline-none"
                    >
                    <p class="mt-1 text-xs text-neutral-400">
                        {{ index === 0 ? 'Gambar utama' : `Urutan ${index + 1}` }}
                    </p>
                </div>

                <div class="flex shrink-0 items-center gap-1">
                    <!--
                        Only on rows that are not the main picture, because on the
                        main picture it would do nothing and say so. The same rule
                        the dead-control note in docs/12 gives: a control that is
                        visible and inert is worse than an absent one.
                    -->
                    <button
                        v-if="index > 0"
                        type="button"
                        class="rounded p-1 text-neutral-500 hover:bg-neutral-100 hover:text-admin-primary"
                        @click="promote(index)"
                    >
                        <Icon name="star" class="h-4 w-4" />
                        <span class="sr-only">Jadikan gambar utama: gambar {{ index + 1 }}</span>
                    </button>

                    <button
                        type="button"
                        class="rounded p-1 text-neutral-500 hover:bg-neutral-100 disabled:cursor-not-allowed disabled:opacity-30"
                        :disabled="index === 0"
                        @click="move(index, -1)"
                    >
                        <Icon name="chevron-up" class="h-4 w-4" />
                        <span class="sr-only">Naikkan gambar {{ index + 1 }}</span>
                    </button>

                    <button
                        type="button"
                        class="rounded p-1 text-neutral-500 hover:bg-neutral-100 disabled:cursor-not-allowed disabled:opacity-30"
                        :disabled="index === modelValue.length - 1"
                        @click="move(index, 1)"
                    >
                        <Icon name="chevron-down" class="h-4 w-4" />
                        <span class="sr-only">Turunkan gambar {{ index + 1 }}</span>
                    </button>

                    <button
                        type="button"
                        class="rounded p-1 text-neutral-500 hover:bg-neutral-100 hover:text-rose-600"
                        @click="drop(index)"
                    >
                        <Icon name="close" class="h-4 w-4" />
                        <span class="sr-only">Hapus gambar {{ index + 1 }}</span>
                    </button>
                </div>
            </li>
        </ul>
    </div>
</template>
