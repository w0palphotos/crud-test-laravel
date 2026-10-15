<script setup>
/**
 * A multi-select that shows its choices as removable pills.
 *
 * Two places use it and they want the same thing from it: pick several tags and
 * be able to take one back without looking for it again. A chosen tag stays
 * visible with its own delete button, which is the part a checkbox list cannot
 * do once there are enough of them to need a scroll.
 *
 * reka-ui supplies the two hard parts — pill keyboard navigation (arrow keys move
 * between pills, Backspace deletes the last, Delete deletes the selected one) and
 * the combobox semantics a screen reader needs. It ships no CSS at all, so the
 * field wears the same Tailwind classes as the inputs around it.
 *
 * The value is an array of ids, not of option objects, and that is deliberate:
 * CreateSale::attachTags() uses each entry as the pivot key directly, so an
 * object here would be written straight into sale_tag and fail. Holding ids also
 * makes this component interchangeable with any other v-model of the same shape,
 * which is how the filter and the form share it.
 *
 * Choosing an existing option is the only way a value gets in. `delimiter=""` is
 * what enforces it: with the default "," a typed word becomes a new tag, and
 * neither the filter nor the sale form may invent rows in a table they do not
 * validate.
 */
import { computed, ref, watch } from 'vue';
import {
    ComboboxAnchor,
    ComboboxContent,
    ComboboxEmpty,
    ComboboxInput,
    ComboboxItem,
    ComboboxItemIndicator,
    ComboboxRoot,
    ComboboxTrigger,
    ComboboxViewport,
    TagsInputInput,
    TagsInputItem,
    TagsInputItemDelete,
    TagsInputItemText,
    TagsInputRoot,
    useFilter,
} from 'reka-ui';

import Icon from './Icon.vue';

const props = defineProps({
    /** Chosen option ids. Mirrors what the backend expects to receive. */
    modelValue: { type: Array, required: true },
    /** The rows that may be chosen, each at least {id, name}. */
    options: { type: Array, required: true },
    /** Property holding the id, when it is not called "id". */
    valueKey: { type: String, default: 'id' },
    /** Property holding the label, when it is not called "name". */
    labelKey: { type: String, default: 'name' },
    /** Optional per-row colour, shown as a dot on the pill and in the list. */
    colourKey: { type: String, default: 'colour' },
    /** Shown while nothing is chosen. */
    placeholder: { type: String, default: '' },
    /** Forwarded to the input so a <label for> can point at this control. */
    inputId: { type: String, default: '' },
    /** Shown when the search matched none of the options. */
    emptyText: { type: String, default: 'Tidak ada yang cocok' },
    /** Set when the surrounding field is in error, to match the other inputs. */
    error: { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue']);

const selected = computed({
    get: () => props.modelValue,
    set: (value) => emit('update:modelValue', value),
});

const query = ref('');
const open = ref(false);

const { contains } = useFilter({ sensitivity: 'base' });

// Compared as strings, following priceOf() in Sales/Form.vue: an id that has
// crossed a form round trip can come back as "3" rather than 3, and a strict
// lookup would miss it and quietly render the bare number on the pill.
const isChosen = (option) => selected.value.some((id) => String(id) === String(option[props.valueKey]));

const matches = (option) => contains(option[props.labelKey], query.value);

const filtered = computed(() => props.options.filter(matches));

// Chosen options first, so the list does not jump under the cursor every time
// a pick is toggled, and already-chosen ones stay findable after the box fills.
const listed = computed(() => [
    ...filtered.value.filter(isChosen),
    ...filtered.value.filter((option) => !isChosen(option)),
]);

const optionById = (id) => props.options.find((option) => String(option[props.valueKey]) === String(id));

const colourOf = (option) => option?.[props.colourKey] ?? null;

/**
 * The label a pill shows, resolved from the id rather than carried alongside it.
 *
 * reka-ui hands this the raw value, so a pill would otherwise render "3". The
 * fallback covers a value that is no longer among the options: it is then shown
 * by id rather than silently dropped, because a sale carrying a tag the desk
 * deleted is a fact about it, not a rendering problem.
 */
const labelOf = (value) => optionById(value)?.[props.labelKey] ?? String(value);

// Searching is for finding an option, not for filtering an existing selection,
// so the box empties once a choice is made or removed. The list is deliberately
// left open: a multi-select whose dropdown closes after each pick is three
// round trips to choose three options.
watch(selected, () => {
    query.value = '';
}, { deep: true });
</script>

<template>
    <!--
            No `by`. Reka's comparator reads a string `by` as a property lookup on
            both sides — compare(1, 2, 'id') becomes 1?.id === 2?.id, which is
            undefined === undefined and therefore true. It then finds the first
            id "equal" to the one being clicked and removes it, so the field
            appeared to accept only one tag. Omitting `by` falls through to a
            structural comparison, which is what bare ids need; `by` is for
            objects matched on an identity property, and this value is not one.
        -->
        <ComboboxRoot v-model="selected" v-model:open="open" multiple>
        <ComboboxAnchor
            class="flex w-full flex-wrap items-center gap-1.5 rounded border bg-admin-surface px-2 py-2 text-sm focus-within:border-admin-primary focus-within:outline-none"
            :class="error ? 'border-rose-400' : open ? 'border-admin-primary' : 'border-neutral-300'"
        >
            <TagsInputRoot
                v-model="selected"
                delimiter=""
                class="flex flex-1 flex-wrap items-center gap-1.5"
            >
                <TagsInputItem
                    v-for="id in selected"
                    :key="id"
                    :value="id"
                    class="flex items-center gap-1 rounded bg-neutral-100 py-0.5 pl-2 pr-1 text-xs text-neutral-800 aria-[current=true]:bg-neutral-200"
                >
                    <span
                        v-if="colourOf(optionById(id))"
                        class="h-2 w-2 rounded-full"
                        :style="{ backgroundColor: colourOf(optionById(id)) }"
                        aria-hidden="true"
                    ></span>
                    <TagsInputItemText>{{ labelOf(id) }}</TagsInputItemText>
                    <TagsInputItemDelete
                        class="rounded p-0.5 text-neutral-400 hover:bg-neutral-200 hover:text-neutral-700"
                    >
                        <Icon name="close" class="h-3 w-3" />
                        <span class="sr-only">Hapus {{ labelOf(id) }}</span>
                    </TagsInputItemDelete>
                </TagsInputItem>

                <!--
                    Enter is swallowed rather than handled: the input sits inside
                    the sale form, and without this the first Enter would submit
                    the sale instead of confirming the highlighted option.
                -->
                <ComboboxInput v-model="query" as-child>
                    <TagsInputInput
                        :id="inputId || undefined"
                        :placeholder="placeholder"
                        class="min-w-24 flex-1 bg-transparent px-1 focus:outline-none"
                        @keydown.enter.prevent
                    />
                </ComboboxInput>
            </TagsInputRoot>

            <ComboboxTrigger class="shrink-0 rounded p-0.5 text-neutral-400 hover:text-neutral-700">
                <Icon name="chevron-down" class="h-4 w-4" />
            </ComboboxTrigger>
        </ComboboxAnchor>

        <!--
            position="popper" rather than an absolutely-positioned panel: near the
            bottom of the page an inline dropdown would be cut off, and this one
            flips above the field instead. It also matches its width to the field,
            so the list never reads as a different control from the input.
        -->
        <ComboboxContent
            position="popper"
            side="bottom"
            align="start"
            :side-offset="4"
            avoid-collisions
            class="z-20 rounded border border-neutral-200 bg-white shadow-lg"
        >
            <ComboboxViewport
                class="max-h-[min(16rem,var(--reka-combobox-content-available-height))] overflow-y-auto p-1"
            >
                <ComboboxItem
                    v-for="option in listed"
                    :key="option[valueKey]"
                    :value="option[valueKey]"
                    class="flex cursor-default items-center gap-2 rounded px-2 py-1.5 text-sm text-neutral-700 data-[highlighted]:bg-admin-primary data-[highlighted]:text-white"
                >
                    <ComboboxItemIndicator class="shrink-0">
                        <Icon name="check-circle" class="h-4 w-4" />
                    </ComboboxItemIndicator>

                    <span
                        v-if="colourOf(option)"
                        class="h-2 w-2 rounded-full"
                        :style="{ backgroundColor: colourOf(option) }"
                        aria-hidden="true"
                    ></span>

                    {{ option[labelKey] }}
                </ComboboxItem>

                <ComboboxEmpty class="px-2 py-2 text-sm text-neutral-400">
                    {{ emptyText }}
                </ComboboxEmpty>
            </ComboboxViewport>
        </ComboboxContent>
    </ComboboxRoot>
</template>