<script setup>
/**
 * The editable body of a sale, shared by the create page and the detail page's
 * edit mode.
 *
 * A component rather than markup copied into Sales/Form.vue and Sales/Show.vue,
 * because the two forms differ in one thing — which endpoint they post to — and in
 * nothing else. Two copies of these fields would drift the way duplicated validation
 * drifts: the label moves in one and the error key stays behind in the other, and
 * a sale created through one would be a different sale from the same numbers
 * entered through the other.
 *
 * It renders onto the surrounding form rather than owning one, so each page keeps
 * its own form element, its own endpoint and its own check that the three selects
 * the browser cannot enforce were actually filled in — see `ensureDetailChosen` in
 * shared/saleForm.js. What is left to share is the fields themselves, and the one
 * thing that has to be identical between the two pages is which fields those are.
 *
 * The running totals below are shown to the user but are never sent. SaleTotals
 * recomputes every one of them from the quantities and prices, because a total that
 * arrived from a browser is not a total anyone should store — and a sale whose
 * stored totals disagree with its own line items cannot be reconciled later
 * without redoing the arithmetic by hand.
 *
 * The unit price defaults to the product's catalogue price when a product is
 * picked, purely as a convenience for typing. It stays editable, because the
 * price actually agreed with the customer routinely differs from the list price,
 * and forcing the user back to the form to correct it would be worse than
 * letting it be wrong in front of them where the total can update.
 */
import { computed } from 'vue';

import MultiSelect from './MultiSelect.vue';
import RichTextEditor from './RichTextEditor.vue';
import SearchSelect from './SearchSelect.vue';
import { blankLine } from '../shared/saleForm.js';
import { useRoutes } from '../shared/routes.js';

const props = defineProps({
    /** The `useForm` instance to bind to. */
    form: { type: Object, required: true },
    customers: { type: Array, required: true },
    salespeople: { type: Array, required: true },
    cities: { type: Array, required: true },
    /** Every tag in the catalogue, not only the ones this sale carries. */
    tags: { type: Array, required: true },
    products: { type: Array, required: true },
    /** What the button says. The two pages save different things. */
    submitLabel: { type: String, default: 'Simpan' },
});

const routes = useRoutes();

function addLine() {
    props.form.items.push(blankLine());
}

function removeLine(index) {
    // Never leave zero lines. The server requires at least one item, so a form
    // that can reach that state submits something it knows will be rejected.
    if (props.form.items.length > 1) {
        props.form.items.splice(index, 1);
    }
}

/**
 * Catalogue price for a product id, used only to prefill the line.
 */
function priceOf(productId) {
    return props.products.find((product) => String(product.id) === String(productId))?.price ?? '';
}

function onProductChosen(line) {
    if (line.unit_price === '' || line.unit_price === null) {
        line.unit_price = priceOf(line.product_id);
    }
}

const lineTotal = (line) =>
    (Number(line.unit_price) || 0) * (Number(line.quantity) || 0) - (Number(line.discount_total) || 0);

const subtotal = computed(() => props.form.items.reduce(
    (sum, line) => sum + (Number(line.unit_price) || 0) * (Number(line.quantity) || 0),
    0,
));

const discountTotal = computed(() =>
    props.form.items.reduce((sum, line) => sum + (Number(line.discount_total) || 0), 0)
    + (Number(props.form.discount_total) || 0)
);

const grandTotal = computed(() => subtotal.value - discountTotal.value + (Number(props.form.tax_total) || 0));

const rupiah = (amount) =>
    new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(amount || 0);
</script>

<template>
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <section class="rounded-lg border border-neutral-200 bg-white p-4">
                <h2 class="text-sm font-semibold">Detail</h2>

                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="customer_id" class="block text-sm font-medium">Pelanggan</label>
                        <SearchSelect
                            input-id="customer_id"
                            v-model="form.customer_id"
                            :options="customers"
                            :error="form.errors.customer_id"
                            placeholder="Pilih pelanggan..."
                            class="mt-1.5"
                        />
                        <p v-if="form.errors.customer_id" class="mt-1.5 text-sm text-rose-600">{{ form.errors.customer_id }}</p>
                    </div>

                    <div>
                        <label for="salesperson_id" class="block text-sm font-medium">Sales</label>
                        <SearchSelect
                            input-id="salesperson_id"
                            v-model="form.salesperson_id"
                            :options="salespeople"
                            :error="form.errors.salesperson_id"
                            placeholder="Pilih sales..."
                            class="mt-1.5"
                        />
                        <p v-if="form.errors.salesperson_id" class="mt-1.5 text-sm text-rose-600">{{ form.errors.salesperson_id }}</p>
                    </div>

                    <div>
                        <label for="city_id" class="block text-sm font-medium">Kota</label>
                        <SearchSelect
                            input-id="city_id"
                            v-model="form.city_id"
                            :options="cities"
                            :error="form.errors.city_id"
                            placeholder="Pilih kota..."
                            class="mt-1.5"
                        />
                        <p v-if="form.errors.city_id" class="mt-1.5 text-sm text-rose-600">{{ form.errors.city_id }}</p>
                    </div>

                    <div>
                        <!--
                            datetime-local rather than date, and the one place the
                            create form differs from what it used to send. A sale is
                            a moment, the detail page prints the hour, and an edit
                            that did not touch the date would otherwise restate it
                            as midnight — a change nobody made.
                        -->
                        <label for="sold_at" class="block text-sm font-medium">Tanggal penjualan</label>
                        <input
                            id="sold_at"
                            v-model="form.sold_at"
                            type="datetime-local"
                            class="mt-1.5 w-full rounded border bg-admin-surface px-3 py-2 text-sm focus:border-admin-primary focus:outline-none"
                            :class="form.errors.sold_at ? 'border-rose-400' : 'border-neutral-300'"
                        >
                        <p v-if="form.errors.sold_at" class="mt-1.5 text-sm text-rose-600">{{ form.errors.sold_at }}</p>
                    </div>
                </div>
            </section>

            <section class="rounded-lg border border-neutral-200 bg-white p-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold">Item</h2>
                    <button
                        type="button"
                        class="rounded-md border border-neutral-300 px-2.5 py-1.5 text-xs font-medium hover:bg-neutral-100"
                        @click="addLine"
                    >
                        Tambah baris
                    </button>
                </div>

                <p v-if="form.errors.items" class="mt-3 text-sm text-rose-600">{{ form.errors.items }}</p>

                <div class="mt-4 space-y-3">
                    <div
                        v-for="(line, index) in form.items"
                        :key="index"
                        class="grid gap-3 rounded border border-neutral-200 p-3 sm:grid-cols-12"
                    >
                        <div class="sm:col-span-4">
                            <label :for="`product-${index}`" class="block text-xs font-medium text-neutral-600">Produk</label>
                            <select
                                :id="`product-${index}`"
                                v-model="line.product_id"
                                required
                                class="mt-1 w-full rounded border bg-admin-surface px-2 py-1.5 text-sm focus:border-admin-primary focus:outline-none"
                                :class="form.errors[`items.${index}.product_id`] ? 'border-rose-400' : 'border-neutral-300'"
                                @change="onProductChosen(line)"
                            >
                                <option value="" disabled>Pilih produk</option>
                                <option v-for="product in products" :key="product.id" :value="product.id">
                                    {{ product.name }}
                                </option>
                            </select>
                            <p v-if="form.errors[`items.${index}.product_id`]" class="mt-1 text-xs text-rose-600">
                                {{ form.errors[`items.${index}.product_id`] }}
                            </p>
                        </div>

                        <div class="sm:col-span-1">
                            <label :for="`quantity-${index}`" class="block text-xs font-medium text-neutral-600">Jumlah</label>
                            <input
                                :id="`quantity-${index}`"
                                v-model="line.quantity"
                                type="number"
                                min="1"
                                required
                                class="mt-1 w-full rounded border bg-admin-surface px-2 py-1.5 text-sm tabular-nums focus:border-admin-primary focus:outline-none"
                                :class="form.errors[`items.${index}.quantity`] ? 'border-rose-400' : 'border-neutral-300'"
                            >
                            <p v-if="form.errors[`items.${index}.quantity`]" class="mt-1 text-xs text-rose-600">
                                {{ form.errors[`items.${index}.quantity`] }}
                            </p>
                        </div>

                        <div class="sm:col-span-2">
                            <label :for="`price-${index}`" class="block text-xs font-medium text-neutral-600">Harga satuan</label>
                            <input
                                :id="`price-${index}`"
                                v-model="line.unit_price"
                                type="number"
                                step="0.01"
                                min="0"
                                required
                                class="mt-1 w-full rounded border bg-admin-surface px-2 py-1.5 text-sm tabular-nums focus:border-admin-primary focus:outline-none"
                                :class="form.errors[`items.${index}.unit_price`] ? 'border-rose-400' : 'border-neutral-300'"
                            >
                            <p v-if="form.errors[`items.${index}.unit_price`]" class="mt-1 text-xs text-rose-600">
                                {{ form.errors[`items.${index}.unit_price`] }}
                            </p>
                        </div>

                        <div class="sm:col-span-2">
                            <label :for="`discount-${index}`" class="block text-xs font-medium text-neutral-600">Diskon</label>
                            <input
                                :id="`discount-${index}`"
                                v-model="line.discount_total"
                                type="number"
                                step="0.01"
                                min="0"
                                placeholder="0.00"
                                class="mt-1 w-full rounded border bg-admin-surface px-2 py-1.5 text-sm tabular-nums focus:border-admin-primary focus:outline-none"
                                :class="form.errors[`items.${index}.discount_total`] ? 'border-rose-400' : 'border-neutral-300'"
                            >
                        </div>

                        <div class="flex items-end justify-end sm:col-span-2">
                            <span class="text-sm font-medium tabular-nums">{{ rupiah(lineTotal(line)) }}</span>
                        </div>

                        <div class="flex items-end justify-center sm:col-span-1">
                            <button
                                type="button"
                                class="rounded px-2 py-1 text-xs text-neutral-500 hover:bg-neutral-100 hover:text-rose-600 disabled:cursor-not-allowed disabled:opacity-40"
                                :disabled="form.items.length === 1"
                                :title="form.items.length === 1 ? 'Penjualan minimal memiliki satu baris' : 'Hapus baris'"
                                @click="removeLine(index)"
                            >
                                Hapus
                            </button>
                        </div>
                    </div>
                </div>
            </section>

            <section class="rounded-lg border border-neutral-200 bg-white p-4">
                <h2 class="text-sm font-semibold">Catatan</h2>
                <p class="mt-1 text-xs text-neutral-500">
                    Instruksi pengiriman, ketentuan yang disepakati, foto barang.
                </p>

                <div class="mt-3">
                    <RichTextEditor
                        v-model="form.note"
                        :error="form.errors.note"
                        :upload-url="routes.uploadsStore"
                    />
                </div>
            </section>
        </div>

        <div class="space-y-6">
            <section class="rounded-lg border border-neutral-200 bg-white p-4">
                <h2 class="text-sm font-semibold">Total</h2>

                <dl class="mt-4 space-y-2 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-neutral-500">Subtotal</dt>
                        <dd class="tabular-nums">{{ rupiah(subtotal) }}</dd>
                    </div>
                </dl>

                <div class="mt-4 space-y-3">
                    <div>
                        <label for="discount_total" class="block text-xs font-medium text-neutral-600">Diskon order</label>
                        <input
                            id="discount_total"
                            v-model="form.discount_total"
                            type="number"
                            step="0.01"
                            min="0"
                            placeholder="0.00"
                            class="mt-1 w-full rounded border bg-admin-surface px-3 py-2 text-sm tabular-nums focus:border-admin-primary focus:outline-none"
                            :class="form.errors.discount_total ? 'border-rose-400' : 'border-neutral-300'"
                        >
                        <p v-if="form.errors.discount_total" class="mt-1 text-xs text-rose-600">{{ form.errors.discount_total }}</p>
                    </div>

                    <div>
                        <label for="tax_total" class="block text-xs font-medium text-neutral-600">Pajak</label>
                        <input
                            id="tax_total"
                            v-model="form.tax_total"
                            type="number"
                            step="0.01"
                            min="0"
                            placeholder="0.00"
                            class="mt-1 w-full rounded border bg-admin-surface px-3 py-2 text-sm tabular-nums focus:border-admin-primary focus:outline-none"
                            :class="form.errors.tax_total ? 'border-rose-400' : 'border-neutral-300'"
                        >
                        <p v-if="form.errors.tax_total" class="mt-1 text-xs text-rose-600">{{ form.errors.tax_total }}</p>
                    </div>
                </div>

                <dl class="mt-4 border-t border-neutral-200 pt-3 text-sm">
                    <div class="flex justify-between font-semibold">
                        <dt>Total akhir</dt>
                        <dd class="tabular-nums">{{ rupiah(grandTotal) }}</dd>
                    </div>
                </dl>

                <p class="mt-3 text-xs text-neutral-400">
                    Dihitung ulang saat disimpan, sehingga total tersimpan selalu cocok dengan item.
                </p>
            </section>

            <!--
                Two controls over one field, on purpose. The checkbox list is the
                readable default, the pillbox below is the same choice in one box
                where a chosen tag can be taken back without hunting for it.
                Both bind form.tags, so they cannot disagree.
            -->
            <section v-if="tags.length" class="rounded-lg border border-neutral-200 bg-white p-4">
                <h2 class="text-sm font-semibold">Tag</h2>

                <div class="mt-3 space-y-2">
                    <label
                        v-for="tag in tags"
                        :key="tag.id"
                        class="flex items-center gap-2 text-sm"
                    >
                        <input
                            v-model="form.tags"
                            type="checkbox"
                            :value="tag.id"
                            class="rounded border-neutral-300 text-admin-primary focus:ring-admin-primary"
                        >
                        <span
                            class="h-2 w-2 rounded-full"
                            :style="{ backgroundColor: tag.colour }"
                            aria-hidden="true"
                        ></span>
                        {{ tag.name }}
                    </label>
                </div>

                <label for="sale-tags-pills" class="mt-4 block text-xs font-medium text-neutral-600">
                    Atau pilih banyak sekaligus
                </label>
                <div class="mt-1.5">
                    <MultiSelect
                        v-model="form.tags"
                        :options="tags"
                        input-id="sale-tags-pills"
                        placeholder="Cari atau pilih tag..."
                    />
                </div>
            </section>

            <button
                type="submit"
                class="w-full rounded-md bg-admin-primary px-3 py-2.5 text-sm font-medium text-white hover:bg-admin-primary-dark disabled:cursor-not-allowed disabled:opacity-40"
                :disabled="form.processing"
            >
                {{ form.processing ? 'Menyimpan...' : submitLabel }}
            </button>
        </div>
    </div>
</template>