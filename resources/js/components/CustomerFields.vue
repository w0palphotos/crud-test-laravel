<script setup>
/**
 * A customer's own three columns: who they are and how to reach them.
 *
 * A component rather than markup copied into Create.vue and Edit.vue, because the
 * two forms differ in one thing — which endpoint they post to — and in nothing
 * else. Two copies of these fields would drift the way duplicated validation
 * drifts: the label moves in one and the error key stays behind in the other.
 *
 * It renders onto the surrounding form rather than owning one, so Create and Edit
 * can keep their separate forms, their separate buttons and their separate
 * endpoints while agreeing on every field between them.
 */
defineProps({
    /** The `useForm` instance to bind to. */
    form: { type: Object, required: true },
});
</script>

<template>
    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <label for="name" class="block text-sm font-medium">Nama</label>
            <input
                id="name"
                v-model="form.name"
                type="text"
                required
                maxlength="255"
                class="mt-1.5 w-full rounded border bg-admin-surface px-3 py-2 text-sm focus:border-admin-primary focus:outline-none"
                :class="form.errors.name ? 'border-rose-400' : 'border-neutral-300'"
            >
            <p v-if="form.errors.name" class="mt-1.5 text-sm text-rose-600">{{ form.errors.name }}</p>
        </div>

        <div>
            <label for="email" class="block text-sm font-medium">Email</label>
            <input
                id="email"
                v-model="form.email"
                type="email"
                required
                maxlength="255"
                class="mt-1.5 w-full rounded border bg-admin-surface px-3 py-2 text-sm focus:border-admin-primary focus:outline-none"
                :class="form.errors.email ? 'border-rose-400' : 'border-neutral-300'"
            >
            <p v-if="form.errors.email" class="mt-1.5 text-sm text-rose-600">{{ form.errors.email }}</p>
        </div>

        <div>
            <label for="phone" class="block text-sm font-medium">Telepon</label>
            <input
                id="phone"
                v-model="form.phone"
                type="tel"
                maxlength="24"
                class="mt-1.5 w-full rounded border bg-admin-surface px-3 py-2 text-sm focus:border-admin-primary focus:outline-none"
                :class="form.errors.phone ? 'border-rose-400' : 'border-neutral-300'"
            >
            <p v-if="form.errors.phone" class="mt-1.5 text-sm text-rose-600">{{ form.errors.phone }}</p>
        </div>
    </div>
</template>