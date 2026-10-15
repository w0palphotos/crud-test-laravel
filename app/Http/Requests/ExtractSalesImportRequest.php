<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExtractSalesImportRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * These three fields are what one document cannot know about itself. A sale is
     * a single order with one customer and one date, so a list of rows cannot carry
     * either: they are asked for here, once, and applied to every row. They are
     * then posted again with the confirmed draft, because the two steps are two
     * requests and nothing is remembered in between. Everything else about the rows
     * is validated by ImportSales, which can say which row is wrong — this form
     * cannot, because it never sees the rows.
     *
     * The status is deliberately absent. The manual sale form sends an empty
     * status and lets CreateSale default it, so a sale arrives Pending either way;
     * offering the choice here would make imports the one way to create a sale in
     * a state the desk cannot otherwise reach by form, which is not a decision an
     * import screen should make on its own. An imported sale moves to any other
     * status through the same status form every other sale uses.
     *
     * `mimetypes` reads the bytes rather than the client's filename, for the
     * reason the image request's docblock sets out at length. The list itself is
     * loose compared to that one, because this file is never served back to a
     * browser — it is decoded and turned into rows — and because the same
     * spreadsheet legitimately arrives under several types. What actually refuses
     * a file is ReadSalesDocument, which opens it and recognises a text report, an
     * Office document or a PDF by its structure, and says so in a message the
     * person can act on.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $allowed = config('imports.mimetypes');

        $maxKilobytes = (int) config('imports.max_kilobytes');

        return [
            'file' => [
                'required',
                'file',
                'mimetypes:'.implode(',', $allowed),
                "max:{$maxKilobytes}",
            ],
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')],
            'sold_at' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $maxKilobytes = (int) config('imports.max_kilobytes');

        return [
            'file.required' => 'Pilih berkas untuk diimpor.',
            'file.mimetypes' => 'Berkas harus berupa .txt, .xlsx, .docx, atau .pdf.',
            'file.max' => "Berkas tidak boleh lebih besar dari {$maxKilobytes} KB.",
            'customer_id.required' => 'Pilih pelanggan untuk seluruh baris.',
            'customer_id.exists' => 'Pelanggan yang dipilih tidak ada.',
            'sold_at.date' => 'Tanggal penjualan tidak valid.',
        ];
    }
}
