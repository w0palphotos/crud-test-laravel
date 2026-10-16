<script setup>
/**
 * A customer's commercial profile: where they are, who pays, and on what terms.
 *
 * The five columns of `customer_profiles`, drawn once for both doors that write
 * them — the create form, which sends the profile with the customer, and the edit
 * page's own profile form.
 *
 * The note above the two money fields is not decoration. `credit_limit` and
 * `payment_terms` are NOT NULL with defaults, so leaving them blank means "zero
 * limit, cash on delivery", not "unknown". The form says so rather than letting
 * someone discover it by saving.
 */
defineProps({
    /** The `useForm` instance to bind to. */
    form: { type: Object, required: true },
    /** The cities a profile may point at. Empty is a real possibility. */
    cities: { type: Array, required: true },
});
</script>

<template>
    <div>
        <p class="mt-1 text-xs text-neutral-500">
            Kosongkan limit dan syarat bayar untuk memakai bawaan: nol, dan COD.
        </p>

        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <div>
                <label for="city_id" class="block text-sm font-medium">Kota</label>
                <select
                    id="city_id"
                    v-model="form.city_id"
                    class="mt-1.5 w-full rounded border bg-admin-surface px-3 py-2 text-sm focus:border-admin-primary focus:outline-none"
                    :class="form.errors.city_id ? 'border-rose-400' : 'border-neutral-300'"
                >
                    <option value="">Pilih kota</option>
                    <option v-for="city in cities" :key="city.id" :value="city.id">
                        {{ city.name }}
                    </option>
                </select>
                <p v-if="form.errors.city_id" class="mt-1.5 text-sm text-rose-600">{{ form.errors.city_id }}</p>
            </div>

            <div>
                <label for="tax_id" class="block text-sm font-medium">NPWP</label>
                <input
                    id="tax_id"
                    v-model="form.tax_id"
                    type="text"
                    maxlength="32"
                    class="mt-1.5 w-full rounded border bg-admin-surface px-3 py-2 text-sm focus:border-admin-primary focus:outline-none"
                    :class="form.errors.tax_id ? 'border-rose-400' : 'border-neutral-300'"
                >
                <p v-if="form.errors.tax_id" class="mt-1.5 text-sm text-rose-600">{{ form.errors.tax_id }}</p>
            </div>

            <div class="sm:col-span-2">
                <label for="address" class="block text-sm font-medium">Alamat</label>
                <textarea
                    id="address"
                    v-model="form.address"
                    rows="2"
                    maxlength="500"
                    class="mt-1.5 w-full rounded border bg-admin-surface px-3 py-2 text-sm focus:border-admin-primary focus:outline-none"
                    :class="form.errors.address ? 'border-rose-400' : 'border-neutral-300'"
                ></textarea>
                <p v-if="form.errors.address" class="mt-1.5 text-sm text-rose-600">{{ form.errors.address }}</p>
            </div>

            <div>
                <label for="credit_limit" class="block text-sm font-medium">Limit kredit</label>
                <input
                    id="credit_limit"
                    v-model="form.credit_limit"
                    type="number"
                    step="0.01"
                    min="0"
                    class="mt-1.5 w-full rounded border bg-admin-surface px-3 py-2 text-sm tabular-nums focus:border-admin-primary focus:outline-none"
                    :class="form.errors.credit_limit ? 'border-rose-400' : 'border-neutral-300'"
                >
                <p v-if="form.errors.credit_limit" class="mt-1.5 text-sm text-rose-600">{{ form.errors.credit_limit }}</p>
            </div>

            <div>
                <label for="payment_terms" class="block text-sm font-medium">Syarat bayar</label>
                <input
                    id="payment_terms"
                    v-model="form.payment_terms"
                    type="text"
                    maxlength="64"
                    class="mt-1.5 w-full rounded border bg-admin-surface px-3 py-2 text-sm focus:border-admin-primary focus:outline-none"
                    :class="form.errors.payment_terms ? 'border-rose-400' : 'border-neutral-300'"
                >
                <p v-if="form.errors.payment_terms" class="mt-1.5 text-sm text-rose-600">{{ form.errors.payment_terms }}</p>
            </div>
        </div>
    </div>
</template>