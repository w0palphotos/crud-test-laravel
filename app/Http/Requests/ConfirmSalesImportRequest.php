<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The confirmed draft, on its way to being written.
 *
 * This request checks the shape and nothing else. It can say that a row is missing
 * a cell, but not that the cell names a product this catalogue does not have —
 * that needs the database, and it needs to be checked for every row at once so the
 * refusal can list all of them. ImportSales owns that, and it is deliberately the
 * same validation the tabular path uses, so a draft that came from a model and a
 * draft that came from a spreadsheet are held to one standard.
 *
 * The rows are trusted no further than the draft screen that produced them: they
 * arrive from the browser, so their contents are re-checked from scratch and their
 * order is what decides their row numbers. A client that sent its own numbering
 * could otherwise make a message point at the wrong row.
 *
 * @return array<string, mixed>
 */
class ConfirmSalesImportRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Which of the two paths read the document, kept because the note on
            // each created sale records it. Constrained to the two values rather
            // than accepted as free text, since it decides that note.
            'origin' => ['required', Rule::in(['table', 'document'])],

            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')],
            'sold_at' => ['nullable', 'date'],

            // Bounded by the same ceiling the extraction was, so a confirmed draft
            // cannot be longer than one that was allowed to be created. The writes
            // are the part that costs something per row.
            'rows' => ['required', 'array', 'min:1', 'max:'.(int) config('imports.max_rows')],

            // `present` rather than `required`, and `nullable` beside it, and both
            // halves matter here. The extractor is instructed to leave a value empty
            // when the document does not carry it, so an empty cell is an expected
            // draft rather than a malformed request — but an empty input arrives as
            // null, because ConvertEmptyStringsToNull runs before validation, so
            // without `nullable` the empty cell fails `string` and the person gets a
            // message about a field not being text.
            //
            // Letting it through is what gets the useful answer — which row, and
            // which value — from ImportSales. The shape is still enforced: a cell
            // that is absent altogether is refused right here.
            'rows.*.cells.sales' => ['present', 'nullable', 'string'],
            'rows.*.cells.kota' => ['present', 'nullable', 'string'],
            'rows.*.cells.produk' => ['present', 'nullable', 'string'],
            'rows.*.cells.jumlah' => ['present', 'nullable', 'string'],
            'rows.*.cells.harga' => ['present', 'nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rows.required' => 'Tidak ada baris untuk disimpan.',
            'rows.min' => 'Tidak ada baris untuk disimpan.',
            'rows.max' => 'Terlalu banyak baris untuk disimpan dalam satu kali.',
            'customer_id.required' => 'Pilih pelanggan untuk seluruh baris.',
            'customer_id.exists' => 'Pelanggan yang dipilih tidak ada.',
            'sold_at.date' => 'Tanggal penjualan tidak valid.',
        ];
    }
}
