<script setup>
/**
 * A supplier's three columns.
 *
 * A component for the same reason as CustomerFields: create and edit differ only
 * in the endpoint they post to, so the fields are drawn once here and both forms
 * bind to their own `useForm`.
 */
defineProps({
    /** The `useForm` instance to bind to. */
    form: { type: Object, required: true },
});
</script>

<template>
    <div class="grid gap-4 sm:grid-cols-2">
        <div class="sm:col-span-2">
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
            <label for="contact_email" class="block text-sm font-medium">Email kontak</label>
            <input
                id="contact_email"
                v-model="form.contact_email"
                type="email"
                maxlength="255"
                class="mt-1.5 w-full rounded border bg-admin-surface px-3 py-2 text-sm focus:border-admin-primary focus:outline-none"
                :class="form.errors.contact_email ? 'border-rose-400' : 'border-neutral-300'"
            >
            <p v-if="form.errors.contact_email" class="mt-1.5 text-sm text-rose-600">{{ form.errors.contact_email }}</p>
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