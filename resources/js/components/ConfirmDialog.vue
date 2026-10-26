<script setup>
/**
 * A destructive action that has to be confirmed first.
 *
 * Guards product deletion and account deletion, which are the same interaction
 * with different wording.
 *
 * The submit is Inertia's Form, not a bare <form>. A bare form would post and
 * navigate, and would need a CSRF token threaded to it for that to work at all;
 * Form carries the XSRF cookie, submits through Inertia so the shell is preserved
 * across the redirect, and hands any validation errors back as props.
 */
import { nextTick, onUnmounted, ref, watch } from 'vue';
import { Form } from '@inertiajs/vue3';

defineProps({
    action: { type: String, required: true },
    /** What leads the question, e.g. "Delete ". */
    prefix: { type: String, default: '' },
    /** The thing being acted on. Rendered in bold inside the question. */
    label: { type: String, required: true },
    /** What follows the label, e.g. "? This cannot be undone." */
    question: { type: String, default: '' },
    triggerLabel: { type: String, required: true },
    confirmLabel: { type: String, default: 'Delete' },
    cancelLabel: { type: String, default: 'Cancel' },
    triggerClass: { type: String, default: '' },
    /** Optional callback invoked after the delete form succeeds. */
    onSuccess: { type: Function, required: false },
});

const open = ref(false);
const cancelButton = ref(null);
let previouslyFocused = null;

function show() {
    // Remembered so focus can go back where it came from. A dialog that opens
    // and leaves focus on the page behind it is disorienting to keyboard users.
    previouslyFocused = document.activeElement;

    open.value = true;
    nextTick(() => cancelButton.value?.focus());
}

function close() {
    open.value = false;
    previouslyFocused?.focus?.();
}

watch(open, (isOpen) => {
    // The page behind the overlay must not scroll under it.
    document.body.style.overflow = isOpen ? 'hidden' : '';
});

onUnmounted(() => {
    document.body.style.overflow = '';
});
</script>

<template>
    <span>
        <button type="button" :class="triggerClass" @click="show">
            {{ triggerLabel }}
        </button>

        <div
            v-show="open"
            class="fixed inset-0 z-50 flex items-center justify-center bg-admin-sidebar/60 p-4"
            @keydown.esc="close"
            @click.self="close"
        >
            <div class="w-full max-w-sm rounded bg-admin-surface p-5 shadow-xl" role="dialog" aria-modal="true">
                <p class="text-sm">
                    {{ prefix }}<span class="font-semibold">{{ label }}</span>{{ question }}
                </p>

                <div class="mt-5 flex items-center justify-end gap-2">
                    <button
                        ref="cancelButton"
                        type="button"
                        class="rounded border border-neutral-300 px-3 py-1.5 text-sm font-medium hover:bg-neutral-100"
                        @click="close"
                    >
                        {{ cancelLabel }}
                    </button>

                    <Form :action="action" method="delete" :on-success="onSuccess" class="inline-block">
                        <button
                            type="submit"
                            class="rounded-md bg-admin-danger px-3 py-1.5 text-sm font-medium text-white hover:brightness-90"
                        >
                            {{ confirmLabel }}
                        </button>
                    </Form>
                </div>
            </div>
        </div>
    </span>
</template>