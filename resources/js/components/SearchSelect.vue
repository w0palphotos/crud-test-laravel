<script setup>
/**
 * A searchable single select, for a field that holds exactly one value.
 *
 * Replaces the native <select> where the option list is long enough that
 * scrolling to one entry is the whole interaction. The value it emits is still
 * a single id, so the form object, the request validation and CreateSale see
 * exactly what they saw before — this is a rendering change, not a contract
 * change.
 *
 * reka-ui supplies the parts that are hard to get right: the combobox ARIA
 * semantics, arrow-key navigation, Escape to close, and the highlighted-option
 * tracking a screen reader announces. It ships no CSS at all, so the field wears
 * the same Tailwind classes as the native selects it replaces, and the vertical
 * list below is the part this file actually decides.
 *
 * The option list is rendered here rather than left to reka-ui's built-in
 * filtering because useFilter goes through Intl.Collator, which is what makes
 * "budi" match "Budi" without case-folding the data itself.
 */
import { computed, ref, watch } from 'vue';
import {
    ComboboxAnchor,
    ComboboxContent,
    ComboboxEmpty,
    ComboboxInput,
    ComboboxItem,
    ComboboxRoot,
    ComboboxTrigger,
    ComboboxViewport,
    useFilter,
} from 'reka-ui';

import Icon from './Icon.vue';

const props = defineProps({
    /** The chosen id, or '' when nothing is chosen. */
    modelValue: { type: [String, Number], default: '' },
    /** Rows that may be chosen, each at least {id, name}. */
    options: { type: Array, required: true },
    /** Property holding the id, when it is not called "id". */
    valueKey: { type: String, default: 'id' },
    /** Property holding the label, when it is not called "name". */
    labelKey: { type: String, default: 'name' },
    /** Rendered in the empty input, and what a screen reader reads out. */
    placeholder: { type: String, default: '' },
    /** Forwarded to the input so a <label for> can point at this control. */
    inputId: { type: String, default: '' },
    /** Validation message from the server, if any. */
    error: { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue']);

const query = ref('');
const open = ref(false);

const { contains } = useFilter({ sensitivity: 'base' });

const matches = (option) => contains(option[props.labelKey], query.value);

const filtered = computed(() => props.options.filter(matches));

const labelOf = (option) => option?.[props.labelKey] ?? '';

const idOf = (option) => option?.[props.valueKey];

const isChosen = (option) => String(idOf(option)) === String(props.modelValue);

/**
 * The chosen row as an object, because reka-ui matches options by identity and
 * a bare id would be a different value from the object in the list.
 *
 * Kept as a computed rather than a ref so a value arriving from outside — a
 * redirect back with old input still in the form — re-resolves instead of
 * going stale.
 */
const chosen = computed(() =>
    props.options.find((option) => String(idOf(option)) === String(props.modelValue)) ?? null,
);

const selected = computed({
    get: () => chosen.value,
    set: (option) => emit('update:modelValue', option === null ? '' : idOf(option)),
});

// The box is for finding an option, not for filtering an existing choice, so it
// empties once one is made. Left open on purpose: this is a single select, so
// choosing is the end of the interaction and closing is what the user expects.
watch(selected, () => {
    query.value = '';
    open.value = false;
});
</script>

<template>
    <ComboboxRoot
        v-model="selected"
        v-model:open="open"
        :ignore-filter="true"
        :aria-invalid="error ? 'true' : undefined"
    >
        <ComboboxAnchor
            class="flex w-full items-center gap-2 rounded border bg-admin-surface px-3 py-2 text-sm focus-within:border-admin-primary focus-within:outline-none"
            :class="error ? 'border-rose-400' : 'border-neutral-300'"
        >
            <ComboboxInput
                :id="inputId || undefined"
                v-model="query"
                :display-value="labelOf"
                :placeholder="placeholder"
                :aria-label="inputId ? undefined : placeholder"
                class="min-w-0 flex-1 bg-transparent focus:outline-none"
                @focus="open = true"
            />

            <ComboboxTrigger class="shrink-0 text-neutral-400 hover:text-neutral-700">
                <Icon name="chevron-down" class="h-4 w-4" />
            </ComboboxTrigger>
        </ComboboxAnchor>

        <ComboboxContent
            position="popper"
            side="bottom"
            align="start"
            :side-offset="4"
            avoid-collisions
            class="z-20 rounded border border-neutral-200 bg-white shadow-lg"
        >
            <ComboboxViewport
                class="max-h-64 overflow-y-auto p-1"
            >
                <ComboboxItem
                    v-for="option in filtered"
                    :key="option[valueKey]"
                    :value="option"
                    class="flex cursor-default items-center gap-2 rounded px-2 py-1.5 text-sm text-neutral-700 data-[highlighted]:bg-admin-primary data-[highlighted]:text-white"
                >
                    <Icon
                        v-if="isChosen(option)"
                        name="check-circle"
                        class="h-4 w-4 shrink-0"
                    />

                    {{ option[labelKey] }}
                </ComboboxItem>

                <ComboboxEmpty class="px-2 py-2 text-sm text-neutral-400">
                    Tidak ada yang cocok
                </ComboboxEmpty>
            </ComboboxViewport>
        </ComboboxContent>
    </ComboboxRoot>
</template>