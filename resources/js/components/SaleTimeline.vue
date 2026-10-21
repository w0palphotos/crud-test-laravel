<script setup>
/**
 * A sale's history: who moved it where, when, and why.
 *
 * Its own component because it carries its own form. The detail page's edit mode
 * wraps the sale's fields in a <form>, and the history is laid out inside that
 * same page — putting an editable note in a <form> there would nest one form
 * inside another, which browsers resolve by making the inner one submit the outer
 * one's fields. Held here as a component with a single per-row form, neither case
 * has to know about the other.
 *
 * Editing a note does not require opening the page's edit mode, and deliberately
 * so: a note is one sentence attached to one moment, and making it wait behind the
 * editor for the whole sale would be weight out of proportion to the change.
 *
 * An amendment is written to this timeline with the same status on both sides,
 * because a sale can change without moving in its lifecycle. So the from/to pair
 * is collapsed when the two are equal: "Menunggu konfirmasi → Menunggu
 * konfirmasi" describes nothing, and reading it as a failed transition would be
 * worse than reading it as what it is — the record was edited.
 */
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    statusEvents: { type: Array, required: true },
    /** Whether the signed-in account may amend a status note. */
    canWrite: { type: Boolean, default: false },
});

/**
 * The event being amended, if any.
 *
 * One row at a time rather than one form per row: a list of mounted inputs would
 * put a dozen textareas on the page for a timeline that is usually three entries
 * long, and the id is enough to render the single one that is open.
 */
const editingId = ref(null);
const form = useForm({ note: '' });

function edit(event) {
    editingId.value = event.id;
    form.note = event.note ?? '';
    form.clearErrors('note');
}

function cancel() {
    editingId.value = null;
    form.note = '';
}

function save(event) {
    form.patch(event.updateUrl, {
        // The page may have the sale editor open behind this, and Inertia replaces
        // the page component on every visit — which would throw away a
        // half-finished amendment to something nobody asked to lose. The props
        // still refresh; only the component instance is kept.
        preserveState: true,
        onSuccess: cancel,
    });
}
</script>

<template>
    <ol class="space-y-3 text-sm">
        <li v-for="event in statusEvents" :key="event.id" class="border-b border-neutral-100 pb-3 last:border-0 last:pb-0">
            <div class="flex flex-wrap items-baseline gap-x-2">
                <span class="font-medium">
                    {{ event.from && event.from !== event.to ? `${event.fromLabel} → ${event.toLabel}` : event.toLabel }}
                </span>
                <span v-if="event.user" class="text-neutral-500">{{ event.user }}</span>
                <span class="text-xs text-neutral-400">{{ event.at }}</span>
            </div>

            <form v-if="editingId === event.id" class="mt-2" @submit.prevent="save(event)">
                <label :for="`event-note-${event.id}`" class="sr-only">Catatan</label>
                <textarea
                    :id="`event-note-${event.id}`"
                    v-model="form.note"
                    rows="2"
                    maxlength="500"
                    placeholder="Opsional"
                    class="w-full rounded border bg-admin-surface px-3 py-2 text-sm focus:border-admin-primary focus:outline-none"
                    :class="form.errors.note ? 'border-rose-400' : 'border-neutral-300'"
                ></textarea>
                <p v-if="form.errors.note" class="mt-1 text-xs text-rose-600">{{ form.errors.note }}</p>

                <div class="mt-2 flex items-center gap-2">
                    <button
                        type="submit"
                        class="rounded-md bg-admin-primary px-2.5 py-1.5 text-xs font-medium text-white hover:bg-admin-primary-dark disabled:cursor-not-allowed disabled:opacity-40"
                        :disabled="form.processing"
                    >
                        {{ form.processing ? 'Menyimpan...' : 'Simpan catatan' }}
                    </button>
                    <button
                        type="button"
                        class="rounded-md border border-neutral-300 px-2.5 py-1.5 text-xs font-medium hover:bg-neutral-100"
                        @click="cancel"
                    >
                        Batal
                    </button>
                </div>
            </form>

            <template v-else>
                <p v-if="event.note" class="mt-0.5 text-neutral-600">{{ event.note }}</p>

                <button
                    v-if="canWrite"
                    type="button"
                    class="mt-1 text-xs text-admin-primary hover:underline"
                    @click="edit(event)"
                >
                    {{ event.note ? 'Ubah catatan' : 'Tambah catatan' }}
                </button>
            </template>
        </li>
    </ol>
</template>