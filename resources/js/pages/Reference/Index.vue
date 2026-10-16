<script setup>
/**
 * One reference list: cities, salespeople or tags, whichever the server said this
 * page is.
 *
 * Everything drawn from `reference` rather than declared here, so the page knows
 * nothing about which of the three it is looking at. Adding a seventh list is an
 * entry in App\Support\ReferenceRegistry, and this file does not change.
 *
 * A refusal to delete is an error keyed on the row that caused it, so the message
 * lands beside that row instead of over the whole table. The server is the one that
 * refuses: `sales` holds these rows with restrictOnDelete, and the tables it would
 * otherwise cascade through are quieter about it, not safer.
 */
import { computed, watch } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';

import AdminLayout from '../../layouts/AdminLayout.vue';
import PanelSection from '../../components/PanelSection.vue';
import ConfirmDialog from '../../components/ConfirmDialog.vue';
import Icon from '../../components/Icon.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    reference: { type: Object, required: true },
    /** Whether this account may change a row of this list. From the server. */
    can: { type: Object, required: true },
    rows: { type: Object, required: true },
    search: { type: String, default: '' },
});

const page = usePage();

const filter = useForm({ q: props.search });

watch(() => props.search, (value) => {
    filter.q = value;
});

function applyFilter() {
    filter.get(props.reference.indexUrl, { preserveState: true, replace: true });
}

function clearFilter() {
    filter.q = '';
    applyFilter();
}

/**
 * Why a row could not be deleted, if it could not.
 *
 * Read from the page's errors rather than from the search form's, because the
 * refusal arrives on the delete request and never touched this form.
 */
const refusal = (id) => page.props.errors?.[`references.${id}`] ?? null;

const pageLinks = computed(() => props.rows.links.filter((link) => link.label === String(link.page)));

/**
 * The column that names the row.
 *
 * Read once here rather than indexed into `reference.columns[0]` at each of its
 * three uses in the template, because an unguarded index in a template is a
 * render-time throw: it takes the page down, not one cell. Every definition lists
 * at least one column, so the fallback is unreachable today — it is here so that
 * adding a list with no listed column degrades into a row of dashes instead of a
 * blank page.
 */
const primaryKey = computed(() => props.reference.columns[0]?.key ?? 'name');

/**
 * A column's value as a cell should read it.
 *
 * Null and empty become a dash: this table is mostly optional columns, and a blank
 * cell reads as something the page forgot to load rather than something nobody set.
 */
const cell = (row, key) => {
    const value = row[key];

    return value === null || value === '' ? '—' : value;
};
</script>

<template>
    <Head :title="reference.label" />

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-medium text-neutral-800">{{ reference.label }}</h1>
            <p class="mt-1 text-sm text-neutral-500">
                {{ rows.total }} {{ reference.label.toLowerCase() }} terdaftar.
            </p>
        </div>

        <!-- Removed rather than disabled for a reader; the write routes are admin-only
             and a greyed button would promise otherwise. See "Kontrol mati dibuang"
             in docs/12. -->
        <Link
            v-if="can.write"
            :href="reference.createUrl"
            class="rounded bg-admin-primary px-3 py-2 text-sm font-medium text-white hover:bg-admin-primary-dark"
        >
            Tambah {{ reference.singular }}
        </Link>
    </div>

    <div class="mt-6">
        <PanelSection
            :title="`Daftar ${reference.label}`"
            :subtitle="`Dipakai saat mencatat penjualan dan memilih ${reference.singular}.`"
        >
            <form class="mb-4 flex flex-wrap gap-2" role="search" @submit.prevent="applyFilter">
                <label for="q" class="sr-only">Cari {{ reference.label.toLowerCase() }}</label>
                <input
                    id="q"
                    v-model="filter.q"
                    type="search"
                    placeholder="Nama"
                    class="w-full max-w-xs rounded border border-neutral-300 px-3 py-2 text-sm focus:border-admin-primary focus:outline-none"
                >
                <button
                    type="submit"
                    class="rounded bg-admin-primary px-3 py-2 text-sm font-medium text-white hover:bg-admin-primary-dark"
                >
                    Cari
                </button>
                <button
                    v-if="filter.q !== ''"
                    type="button"
                    class="px-2 py-2 text-sm text-neutral-500 hover:underline"
                    @click="clearFilter"
                >
                    Reset
                </button>
            </form>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[32rem] text-left text-sm">
                    <thead class="border-b border-neutral-200 text-xs uppercase tracking-wide text-neutral-500">
                        <tr>
                            <th
                                v-for="column in reference.columns"
                                :key="column.key"
                                scope="col"
                                class="py-2 font-medium"
                                :class="column.numeric ? 'text-right' : ''"
                            >
                                {{ column.label }}
                            </th>
                            <th v-if="can.write" scope="col" class="py-2 text-right font-medium">Aksi</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-neutral-100">
                        <template v-for="row in rows.data" :key="row.id">
                            <tr>
                                <td
                                    v-for="column in reference.columns"
                                    :key="column.key"
                                    class="py-2.5"
                                    :class="[
                                        column.numeric ? 'text-right tabular-nums text-neutral-600' : 'text-neutral-600',
                                        column.key === primaryKey ? 'font-medium text-neutral-800' : '',
                                    ]"
                                >
                                    <span
                                        v-if="column.type === 'colour' && row[column.key]"
                                        class="inline-flex items-center gap-1.5 font-mono text-xs"
                                    >
                                        <span
                                            class="size-3 rounded-full border border-neutral-200"
                                            :style="{ backgroundColor: row[column.key] }"
                                            aria-hidden="true"
                                        ></span>
                                        {{ row[column.key] }}
                                    </span>

                                    <template v-else>
                                        {{ cell(row, column.key) }}
                                    </template>
                                </td>

                                <td v-if="can.write" class="py-2.5 text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        <Link
                                            :href="row.editUrl"
                                            class="rounded px-2 py-1 text-sm font-medium text-neutral-700 hover:bg-neutral-100"
                                        >
                                            Ubah
                                        </Link>

                                        <ConfirmDialog
                                            :action="row.destroyUrl"
                                            :prefix="`Hapus ${reference.singular} `"
                                            :label="cell(row, primaryKey)"
                                            question="? Baris yang masih dipakai penjualan tidak bisa dihapus."
                                            trigger-label="Hapus"
                                            confirm-label="Hapus"
                                            trigger-class="rounded px-2 py-1 text-sm font-medium text-admin-danger hover:bg-orange-50"
                                        />
                                    </div>
                                </td>
                            </tr>

                            <tr v-if="refusal(row.id)" class="bg-orange-50">
                                <td :colspan="reference.columns.length + (can.write ? 1 : 0)" class="px-2 py-2">
                                    <p class="flex items-start gap-1.5 text-sm text-orange-800">
                                        <Icon name="alert" class="mt-0.5 h-4 w-4 shrink-0" />
                                        <span>{{ refusal(row.id) }}</span>
                                    </p>
                                </td>
                            </tr>
                        </template>

                        <tr v-if="rows.data.length === 0">
                            <td :colspan="reference.columns.length + (can.write ? 1 : 0)" class="py-10 text-center text-sm text-neutral-500">
                                Belum ada {{ reference.label.toLowerCase() }} yang cocok.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <nav v-if="rows.last_page > 1" class="mt-4 flex justify-end gap-1 text-sm">
                <Link
                    v-for="link in pageLinks"
                    :key="link.page"
                    :href="link.url"
                    preserve-scroll
                    class="rounded px-3 py-1.5"
                    :class="link.active ? 'bg-admin-primary text-white' : 'border border-neutral-300 hover:bg-neutral-100'"
                >
                    {{ link.label }}
                </Link>
            </nav>
        </PanelSection>
    </div>
</template>
