<script setup>
/**
 * One sale, with the note as it was typed.
 *
 * `v-html` on the note is the sharpest edge in this application. It is safe only
 * because CreateSale ran the value through SanitiseRichText before storing it —
 * the note is rendered as markup deliberately, so a link in a note is a link and
 * not a wall of escaped angle brackets. Nothing sanitises at render time, so
 * there is exactly one place to get right, and it is on the write path where a
 * forgotten future edit fails closed rather than open.
 */
import { Head, Link, useForm } from '@inertiajs/vue3';

import AdminLayout from '../../layouts/AdminLayout.vue';
import PanelSection from '../../components/PanelSection.vue';
import { useRoutes } from '../../shared/routes.js';

defineOptions({ layout: AdminLayout });

defineProps({
    sale: { type: Object, required: true },
    items: { type: Array, required: true },
    tags: { type: Array, required: true },
    statusEvents: { type: Array, required: true },
    nextStatuses: { type: Array, required: true },
    statusUpdateUrl: { type: String, required: true },
});

const routes = useRoutes();

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

        <Link
            :href="routes.sales"
            class="shrink-0 rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium hover:bg-neutral-100"
        >
            Kembali ke penjualan
        </Link>
    </div>

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
                                {{ item.product }}
                                <span class="block text-xs text-neutral-400">#{{ item.productId }}</span>
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

            <PanelSection title="Riwayat">
                <ol class="space-y-3 text-sm">
                    <li v-for="event in statusEvents" :key="`${event.at}-${event.to}`">
                        <div class="flex flex-wrap items-baseline gap-x-2">
                            <span class="font-medium">
                                {{ event.fromLabel ? `${event.fromLabel} → ${event.toLabel}` : event.toLabel }}
                            </span>
                            <span v-if="event.user" class="text-neutral-500">{{ event.user }}</span>
                            <span class="text-xs text-neutral-400">{{ event.at }}</span>
                        </div>
                        <p v-if="event.note" class="mt-0.5 text-neutral-600">{{ event.note }}</p>
                    </li>
                </ol>
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

            <PanelSection title="Ubah status">
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