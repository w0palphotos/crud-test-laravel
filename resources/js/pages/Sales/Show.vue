<script setup>
/**
 * One sale, and the editor for amending it.
 *
 * `v-html` on the note is the sharpest edge in this application. It is safe only
 * because every write ran the value through SanitiseRichText before storing it —
 * the note is rendered as markup deliberately, so a link in a note is a link and
 * not a wall of escaped angle brackets. Nothing sanitises at render time, so there
 * is exactly one place to get right, and it is on the write path: CreateSale when
 * recording one, UpdateSale when amending it. Both do it, which is the whole
 * reason an amendment is a safe door rather than a second one.
 *
 * The editor is the same one the create form uses. Two forms for one set of fields
 * would drift the way duplicated validation drifts, so the fields live in
 * SaleEditor and this page supplies the two things that differ — the values to
 * start from, and the endpoint to save them to.
 *
 * The editor opens itself when the server comes back with errors. A PATCH that
 * fails validation redirects here, and a person told "baris 2 salah" while looking
 * at a read-only page would have no idea which line was meant.
 */
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

import AdminLayout from '../../layouts/AdminLayout.vue';
import Icon from '../../components/Icon.vue';
import PanelSection from '../../components/PanelSection.vue';
import SaleDocuments from '../../components/SaleDocuments.vue';
import SaleEditor from '../../components/SaleEditor.vue';
import SaleTimeline from '../../components/SaleTimeline.vue';
import { ensureDetailChosen } from '../../shared/saleForm.js';
import { useRoutes } from '../../shared/routes.js';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    /** Whether the signed-in account may change this sale. */
    can: { type: Object, required: true },
    sale: { type: Object, required: true },
    items: { type: Array, required: true },
    /** The tags this sale already carries. The editor's choices are `tagOptions`. */
    tags: { type: Array, required: true },
    tagOptions: { type: Array, required: true },
    statusEvents: { type: Array, required: true },
    documents: { type: Array, required: true },
    documentStoreUrl: { type: String, required: true },
    nextStatuses: { type: Array, required: true },
    statusUpdateUrl: { type: String, required: true },
    updateUrl: { type: String, required: true },
    customers: { type: Array, required: true },
    salespeople: { type: Array, required: true },
    cities: { type: Array, required: true },
    products: { type: Array, required: true },
});

const routes = useRoutes();

/**
 * The order's own discount, recovered from the stored total.
 *
 * A sale stores one discount_total for the whole order, which is the line
 * discounts rolled up plus whatever was taken off the order on top. So the part
 * that belongs in the order-level input is the difference between the two, and the
 * arithmetic inverts exactly — SaleTotals rounds each line before adding it, and
 * rounds once more at the end, so nothing is lost in between.
 *
 * The alternative is to show nothing and let the field start empty, which is not
 * an alternative: saving that would silently drop the order discount and change
 * the total of a sale nobody had touched.
 */
function orderDiscount() {
    const rolledUp = Number(props.sale.discountTotal) || 0;
    const fromLines = props.items.reduce((sum, item) => sum + (Number(item.discountTotal) || 0), 0);

    return Math.max(0, rolledUp - fromLines);
}

/**
 * The sale as the form sees it.
 *
 * Built from the page's own props, so it is also what cancelling an edit restores:
 * nothing about this form comes from anywhere but the record, and rebuilding it
 * from the props is a copy rather than a second opinion about what is stored.
 *
 * The header totals are deliberately absent. The request does not accept them and
 * SaleTotals recomputes them, so carrying them here would only invite a reader to
 * think the posted value mattered.
 */
function seed() {
    return {
        customer_id: props.sale.customerId,
        salesperson_id: props.sale.salespersonId,
        city_id: props.sale.cityId,
        sold_at: props.sale.soldAtInput,
        discount_total: orderDiscount(),
        tax_total: props.sale.taxTotal,
        note: props.sale.note ?? '',
        items: props.items.map((item) => ({
            product_id: item.productId,
            quantity: item.quantity,
            unit_price: item.unitPrice,
            discount_total: item.discountTotal,
        })),
        tags: props.tags.map((tag) => tag.id),

        // The documents filed with the sale. Carried in the form so one can be
        // added while the sale is being amended, and so the sale and its
        // documents are always saved by the same request.
        documents: [],
    };
}

const form = useForm(seed());

const editing = ref(Object.keys(form.errors).length > 0);

function cancel() {
    Object.assign(form, seed());
    form.clearErrors();
    editing.value = false;
}

function save() {
    if (! ensureDetailChosen(form)) {
        return;
    }

    // No preserveState: the record has just changed, so a component left mounted
    // over the top of it would show the version the person came to correct.
    form.patch(props.updateUrl, { onSuccess: () => { editing.value = false; } });
}

const statusForm = useForm({
    status: '',
    note: '',
});

function saveStatus(url) {
    statusForm.patch(url);
}

const rupiah = (amount) =>
    new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR' }).format(amount || 0);
</script>

<template>
    <Head :title="`Penjualan ${sale.reference}`" />

    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="text-lg font-semibold tracking-tight">{{ sale.reference }}</h1>
            <p class="mt-1 text-sm text-neutral-500">
                {{ sale.customer }} &middot; {{ sale.city }} &middot; {{ sale.soldAt }}
            </p>
        </div>

        <div class="flex shrink-0 items-center gap-2">
            <template v-if="can.write">
                <button
                    v-if="editing"
                    type="button"
                    class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium hover:bg-neutral-100"
                    @click="cancel"
                >
                    Batal
                </button>

                <button
                    v-else
                    type="button"
                    class="rounded-md bg-admin-primary px-3 py-2 text-sm font-medium text-white hover:bg-admin-primary-dark"
                    @click="editing = true"
                >
                    Ubah
                </button>
            </template>

            <Link
                :href="routes.sales"
                class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium hover:bg-neutral-100"
            >
                Kembali ke penjualan
            </Link>
        </div>
    </div>

    <template v-if="editing">
        <form class="mt-6" @submit.prevent="save">
            <SaleEditor
                :form="form"
                :customers="customers"
                :salespeople="salespeople"
                :cities="cities"
                :tags="tagOptions"
                :products="products"
                submit-label="Simpan perubahan"
            />
        </form>

        <p class="mt-3 text-sm text-neutral-500">
            Nomor referensi tidak berubah. Status tetap lewat perpindahan di panel status.
        </p>
    </template>

    <template v-else>
        <div class="mt-6 grid gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                <PanelSection title="Item">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-neutral-200 text-left text-xs text-neutral-500">
                                <th class="pb-2 font-medium">Produk</th>
                                <th class="pb-2 text-right font-medium">Jumlah</th>
                                <th class="pb-2 text-right font-medium">Harga satuan</th>
                                <th class="pb-2 text-right font-medium">Diskon</th>
                                <th class="pb-2 text-right font-medium">Total baris</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(item, index) in items" :key="index" class="border-b border-neutral-100 last:border-0">
                                <td class="py-2">
                                    <!--
                                        The thumbnail sits inside the cell rather than
                                        in a column of its own, and for the reason the
                                        catalogue's list gives: a separate column would
                                        have to be declared, and every line of a sale
                                        whose products have no picture would leave it
                                        holding a placeholder — a column of placeholders
                                        reads as broken thumbnails rather than as
                                        products that have none.

                                        Shown even though the line's identity is the id
                                        printed underneath: the sale records what was
                                        agreed, and the picture is how someone recognises
                                        which physical thing that was.
                                    -->
                                    <div class="flex items-start gap-3">
                                        <img
                                            v-if="item.thumbnailUrl"
                                            :src="item.thumbnailUrl"
                                            :alt="item.product"
                                            class="size-10 shrink-0 rounded object-cover"
                                            loading="lazy"
                                        >
                                        <span
                                            v-else
                                            aria-hidden="true"
                                            class="grid size-10 shrink-0 place-items-center rounded bg-neutral-100 text-neutral-300"
                                        >
                                            <Icon name="box" class="size-5" />
                                        </span>

                                        <span class="min-w-0">
                                            {{ item.product }}
                                            <span class="block text-xs text-neutral-400">#{{ item.productId }}</span>
                                        </span>
                                    </div>
                                </td>
                                <td class="py-2 text-right tabular-nums">{{ item.quantity }}</td>
                                <td class="py-2 text-right tabular-nums">{{ rupiah(item.unitPrice) }}</td>
                                <td class="py-2 text-right tabular-nums">{{ rupiah(item.discountTotal) }}</td>
                                <td class="py-2 text-right font-medium tabular-nums">{{ rupiah(item.lineTotal) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </PanelSection>

                <PanelSection v-if="sale.note" title="Catatan">
                    <!-- Sanitised on write. See the note at the top of this file. -->
                    <div class="prose-editor text-sm" v-html="sale.note"></div>
                </PanelSection>
            </div>

            <div class="space-y-6">
                <PanelSection title="Ringkasan">
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-neutral-500">Status</dt>
                            <dd class="font-medium">{{ sale.statusLabel }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-neutral-500">Sales</dt>
                            <dd>{{ sale.salesperson }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-neutral-500">Subtotal</dt>
                            <dd class="tabular-nums">{{ rupiah(sale.subtotal) }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-neutral-500">Diskon</dt>
                            <dd class="tabular-nums">{{ rupiah(sale.discountTotal) }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-neutral-500">Pajak</dt>
                            <dd class="tabular-nums">{{ rupiah(sale.taxTotal) }}</dd>
                        </div>
                        <div class="flex justify-between border-t border-neutral-200 pt-2 font-semibold">
                            <dt>Total akhir</dt>
                            <dd class="tabular-nums">{{ rupiah(sale.grandTotal) }}</dd>
                        </div>
                    </dl>
                </PanelSection>

                <PanelSection v-if="can.write" title="Ubah status">
                    <form v-if="nextStatuses.length" @submit.prevent="saveStatus(statusUpdateUrl)" class="space-y-3">
                        <!--
                            Radio rather than a select: every status has at most two
                            onward moves, and a dropdown spends a click to open a list
                            of two while hiding the alternative. The shared `name` is
                            what makes them one group — without it each input is its
                            own group and they can all end up checked at once.
                        -->
                        <fieldset>
                            <legend class="block text-sm font-medium">Status berikutnya</legend>

                            <div class="mt-1.5 space-y-2">
                                <label
                                    v-for="status in nextStatuses"
                                    :key="status.value"
                                    class="flex items-center gap-2 text-sm"
                                >
                                    <input
                                        v-model="statusForm.status"
                                        type="radio"
                                        name="status"
                                        :value="status.value"
                                        required
                                        class="border-neutral-300 text-admin-primary focus:ring-admin-primary"
                                    >
                                    {{ status.label }}
                                </label>
                            </div>

                            <p v-if="statusForm.errors.status" class="mt-1.5 text-sm text-rose-600">{{ statusForm.errors.status }}</p>
                        </fieldset>

                        <div>
                            <label for="status-note" class="block text-sm font-medium">Catatan status</label>
                            <textarea
                                id="status-note"
                                v-model="statusForm.note"
                                rows="2"
                                maxlength="500"
                                placeholder="Opsional"
                                class="mt-1.5 w-full rounded border bg-admin-surface px-3 py-2 text-sm focus:border-admin-primary focus:outline-none"
                                :class="statusForm.errors.note ? 'border-rose-400' : 'border-neutral-300'"
                            ></textarea>
                            <p v-if="statusForm.errors.note" class="mt-1.5 text-sm text-rose-600">{{ statusForm.errors.note }}</p>
                        </div>

                        <button
                            type="submit"
                            class="w-full rounded-md bg-admin-primary px-3 py-2 text-sm font-medium text-white hover:bg-admin-primary-dark disabled:cursor-not-allowed disabled:opacity-40"
                            :disabled="statusForm.processing"
                        >
                            Simpan status
                        </button>
                    </form>

                    <p v-else class="text-sm text-neutral-600">
                        Status ini sudah final dan tidak dapat diubah lagi.
                    </p>
                </PanelSection>

                <PanelSection v-if="tags.length" title="Tag">
                    <div class="flex flex-wrap gap-2">
                        <!--
                            The colour tints the background only. Every tag colour in
                            the catalogue is a pale pastel, so using it as the text
                            colour too put near-white on near-white: "repeat-customer"
                            was unreadable at exactly the size it is meant to be read
                            at. The hue still does the categorising; the text stays
                            dark enough to read.
                        -->
                        <span
                            v-for="tag in tags"
                            :key="tag.id"
                            class="rounded-full border px-2.5 py-1 text-xs font-medium text-neutral-900"
                            :style="{ backgroundColor: tag.colour, borderColor: `${tag.colour}` }"
                        >
                            {{ tag.name }}
                        </span>
                    </div>
                </PanelSection>
            </div>
        </div>
    </template>

    <!--
        Outside the edit form above, in both modes: the timeline carries its own
        form per row, and a form inside a form is the browser's to resolve. The
        documents are here for the same reason — filing one is not an amendment
        to the sale, so it does not belong to the amendment.
    -->
    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <SaleDocuments
            :documents="documents"
            :store-url="documentStoreUrl"
            :can-write="can.write"
        />

        <PanelSection title="Riwayat">
            <SaleTimeline :status-events="statusEvents" :can-write="can.write" />
        </PanelSection>
    </div>
</template>