<script setup>
/**
 * A page section that all pages in the shell share: a heading, an optional
 * subtitle, an optional action on the right, and a white card around the body.
 *
 * Exists because every page would otherwise repeat the same card chrome, and the
 * shadow and padding that make a card read as a card are exactly the details
 * that drift apart when they are written out five times.
 *
 * A single root, so class falls through to the <section> from the caller. That is
 * deliberate: the grid span a panel needs in a particular layout is that layout's
 * business, and declaring an attrs prop here to forward it would only hide the
 * standard behaviour.
 */
defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: '' },
});
</script>

<template>
    <section>
        <div class="mb-3 flex flex-wrap items-end justify-between gap-2">
            <div>
                <h2 class="text-lg font-medium text-neutral-800">{{ title }}</h2>
                <p v-if="subtitle" class="mt-0.5 text-sm text-neutral-500">{{ subtitle }}</p>
            </div>

            <slot name="action" />
        </div>

        <div class="rounded bg-admin-surface p-4 shadow-admin-card">
            <slot />
        </div>
    </section>
</template>