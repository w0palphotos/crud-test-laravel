<script setup>
/**
 * The product fields, shared by the create and edit pages so the two cannot
 * disagree about a label, a maxlength or where an error appears.
 *
 * Takes the form object rather than declaring its own fields, so v-model writes
 * straight into the caller's useForm state and errors land on the same object.
 * Inertia fills form.errors from the shared errors prop after Laravel redirects
 * back from a failed FormRequest, so this reads one error source, not two.
 */
defineProps({
    form: { type: Object, required: true },
});
</script>

<template>
    <div class="space-y-4">
        <div>
            <label for="name" class="block text-sm font-medium">Nama</label>
            <input
                id="name"
                v-model="form.name"
                type="text"
                maxlength="255"
                required
                class="mt-1.5 w-full rounded border bg-admin-surface px-3 py-2 text-sm focus:border-admin-primary focus:outline-none"
                :class="form.errors.name ? 'border-rose-400' : 'border-neutral-300'"
            >
            <p v-if="form.errors.name" class="mt-1.5 text-sm text-rose-600">{{ form.errors.name }}</p>
        </div>

        <div>
            <label for="description" class="block text-sm font-medium">Deskripsi</label>
            <textarea
                id="description"
                v-model="form.description"
                rows="3"
                maxlength="1000"
                class="mt-1.5 w-full rounded border bg-admin-surface px-3 py-2 text-sm focus:border-admin-primary focus:outline-none"
                :class="form.errors.description ? 'border-rose-400' : 'border-neutral-300'"
            ></textarea>
            <p v-if="form.errors.description" class="mt-1.5 text-sm text-rose-600">{{ form.errors.description }}</p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="price" class="block text-sm font-medium">Harga</label>
                <input
                    id="price"
                    v-model="form.price"
                    type="text"
                    inputmode="decimal"
                    required
                    placeholder="0.00"
                    class="mt-1.5 w-full rounded border bg-admin-surface px-3 py-2 text-sm tabular-nums focus:border-admin-primary focus:outline-none"
                    :class="form.errors.price ? 'border-rose-400' : 'border-neutral-300'"
                >
                <p v-if="form.errors.price" class="mt-1.5 text-sm text-rose-600">{{ form.errors.price }}</p>
            </div>

            <div>
                <label for="stock" class="block text-sm font-medium">Stok</label>
                <input
                    id="stock"
                    v-model="form.stock"
                    type="number"
                    min="0"
                    step="1"
                    required
                    class="mt-1.5 w-full rounded border bg-admin-surface px-3 py-2 text-sm tabular-nums focus:border-admin-primary focus:outline-none"
                    :class="form.errors.stock ? 'border-rose-400' : 'border-neutral-300'"
                >
                <p v-if="form.errors.stock" class="mt-1.5 text-sm text-rose-600">{{ form.errors.stock }}</p>
            </div>
        </div>
    </div>
</template>