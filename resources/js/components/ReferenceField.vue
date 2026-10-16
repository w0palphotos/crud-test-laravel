<script setup>
/**
 * One field of a reference form.
 *
 * The reference lists are described on the server by a definition, and this
 * renders whichever field it names rather than a page per table. Five input types
 * cover all three lists; a sixth list with a field none of them fits would add a
 * branch here and nothing else.
 *
 * The error is this component's own concern because a form built from a definition
 * cannot wire a per-field message by hand — the fields are not known when the page
 * is written.
 */
import { computed } from 'vue';

import Icon from './Icon.vue';

const props = defineProps({
    /** `{ key, label, type, optional, numeric }` from the definition. */
    field: { type: Object, required: true },
    modelValue: { type: String, default: '' },
    /** The validation message for this field, if the server sent one. */
    error: { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue']);

// Computed rather than read once, because `field` is a prop and a prop is a
// promise about the future, not a guarantee about this instant.
const inputId = computed(() => `reference-${props.field.key}`);
const errorId = computed(() => `${inputId.value}-error`);

/**
 * The HTML input type for a field kind.
 *
 * `decimal` is a text input with a numeric keypad rather than type="number":
 * number inputs silently discard what does not parse, so a half-typed "12," turns
 * into an empty value and the form then refuses the field the person was typing.
 * The server validates the digits either way, and ProductFields' price field
 * already reads this way.
 */
const inputType = (type) => {
    switch (type) {
        case 'email':
            return 'email';

        case 'tel':
            return 'tel';

        default:
            return 'text';
    }
};

const update = (event) => emit('update:modelValue', event.target.value);
</script>

<template>
    <div>
        <label :for="inputId" class="block text-sm font-medium">
            {{ field.label }}
            <span v-if="field.optional" class="font-normal text-neutral-500">(opsional)</span>
        </label>

        <!--
            A colour input and the hex it holds, side by side. The control alone
            gives no way to read the value back or to type one the picker cannot
            produce, and the desk copies hex out of a palette elsewhere.
        -->
        <div v-if="field.type === 'colour'" class="mt-1.5 flex items-center gap-2">
            <input
                :id="inputId"
                type="color"
                :value="modelValue || '#e6e8eb'"
                :aria-invalid="error ? 'true' : undefined"
                class="size-9 shrink-0 cursor-pointer rounded border border-neutral-300 bg-white p-0.5"
                @input="update"
            >
            <input
                type="text"
                :value="modelValue"
                maxlength="7"
                spellcheck="false"
                :aria-label="`${field.label} (hex)`"
                :aria-invalid="error ? 'true' : undefined"
                class="w-32 flex-1 rounded border bg-admin-surface px-3 py-2 font-mono text-sm focus:border-admin-primary focus:outline-none"
                :class="error ? 'border-rose-400' : 'border-neutral-300'"
                @input="update"
            >
        </div>

        <input
            v-else
            :id="inputId"
            :type="inputType(field.type)"
            :value="modelValue"
            :inputmode="field.type === 'decimal' ? 'decimal' : undefined"
            :required="! field.optional"
            :aria-invalid="error ? 'true' : undefined"
            :aria-describedby="error ? errorId : undefined"
            class="mt-1.5 w-full rounded border bg-admin-surface px-3 py-2 text-sm focus:border-admin-primary focus:outline-none"
            :class="[
                field.numeric ? 'tabular-nums' : '',
                error ? 'border-rose-400' : 'border-neutral-300',
            ]"
            @input="update"
        >

        <p v-if="error" :id="errorId" class="mt-1.5 text-sm text-rose-600">
            <Icon name="alert" class="mr-1 inline h-3.5 w-3.5" />
            {{ error }}
        </p>
    </div>
</template>
