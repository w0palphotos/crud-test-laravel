<script setup>
/**
 * The form for one row of a reference list, adding or changing.
 *
 * One page for both because the two are the same shape: the server describes the
 * list's fields, the fields are drawn from that description, and the only real
 * difference between adding and changing is which URL the form posts to. Splitting
 * them would mean a second page per list to keep in step with the first.
 *
 * `row` is null when adding. It is the whole of the distinction, and the page
 * never guesses it from the URL.
 */
import { computed, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

import AdminLayout from '../../layouts/AdminLayout.vue';
import PanelSection from '../../components/PanelSection.vue';
import ReferenceField from '../../components/ReferenceField.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    /** The list being written: key, label, singular, fields, and the list URLs. */
    reference: { type: Object, required: true },
    /** The stored row when changing; null when adding. */
    row: { type: Object, default: null },
    /** One value per field, as strings. */
    values: { type: Object, required: true },
    storeUrl: { type: String, default: '' },
    updateUrl: { type: String, default: '' },
    cancelUrl: { type: String, default: '' },
});

// Computed rather than a plain const, and this is the same trap the sidebar fell
// into. Adding and changing are the same page component, so Inertia reuses this
// instance when the route moves between them: a `const` read at setup would keep
// saying "adding" after the row arrived, and the form would POST to the add
// endpoint with an empty action instead of PATCHing the row.
const changing = computed(() => Boolean(props.row));
const form = useForm({ ...props.values });

// And the values have to reset for the same reason: without this, arriving at the
// edit form after filling in the add form would show the half-typed row above it.
watch(
    () => props.values,
    (values) => {
        form.defaults = { ...values };
        form.reset();
    },
);

const heading = () => (props.row
    ? `Ubah ${props.reference.singular}`
    : `Tambah ${props.reference.singular}`);

const description = () => (props.row
    ? 'Perubahan langsung berlaku di mana pun daftar ini dipilih.'
    : `Ditambahkan ke daftar ${props.reference.label.toLowerCase()}.`);

/**
 * `props.row`, not `changing`, in the script.
 *
 * A `computed` is a ref, and a ref is an object — in `<script setup>` the template
 * unwraps one for you and the script does not. So `if (changing)` in a function is
 * true whatever the sale, and the branch that decides whether this form adds a row
 * or changes one becomes unconditional: every add was sent as a PATCH to an
 * `updateUrl` the create page never sends, which Inertia resolves to the current
 * URL, which the route table reads as a row id of "create" and answers 404.
 *
 * The template may still use `changing`, because there the unwrapping is real. The
 * rule the guard in tests/Unit/RefUnwrappingTest enforces follows from this: a ref
 * is a template concept, and reading one in a script means writing `.value`.
 */
function submit() {
    if (props.row) {
        form.patch(props.updateUrl);

        return;
    }

    form.post(props.storeUrl);
}
</script>

<template>
    <Head :title="heading()" />

    <div class="mb-6">
        <h1 class="text-xl font-medium text-neutral-800">{{ heading() }}</h1>
        <p class="mt-1 text-sm text-neutral-500">{{ description() }}</p>
    </div>

    <PanelSection :title="reference.label" :subtitle="`Kolom untuk satu ${reference.singular}.`">
        <form class="max-w-lg space-y-4" @submit.prevent="submit">
            <ReferenceField
                v-for="field in reference.fields"
                :key="field.key"
                v-model="form[field.key]"
                :field="field"
                :error="form.errors[field.key]"
            />

            <div class="flex items-center gap-2 pt-2">
                <button
                    type="submit"
                    class="rounded bg-admin-primary px-3 py-2 text-sm font-medium text-white hover:bg-admin-primary-dark disabled:opacity-60"
                    :disabled="form.processing"
                >
                    {{ changing ? 'Simpan' : 'Tambah' }}
                </button>

                <Link
                    :href="cancelUrl"
                    class="px-2 py-2 text-sm text-neutral-500 hover:underline"
                >
                    Batal
                </Link>
            </div>
        </form>
    </PanelSection>
</template>
