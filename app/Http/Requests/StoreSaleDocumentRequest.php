<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Filing a document with a sale.
 *
 * The type is checked against what the bytes actually are, not against what the
 * file is called: a rename turns a spreadsheet into something that looks like a
 * document, and the check has to be about the file rather than about its name.
 *
 * The size is the one in config/documents.php, which is generous on purpose —
 * these are documents people read, and the ceiling that matters is the one on
 * storage, not a tighter one here.
 */
class StoreSaleDocumentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $allowed = config('documents.mimetypes');
        $maxKilobytes = (int) config('documents.max_kilobytes');

        return [
            'file' => [
                'required',
                'file',
                'mimetypes:'.implode(',', $allowed),
                "max:{$maxKilobytes}",
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.mimetypes' => 'Dokumen harus berupa PDF, DOCX, atau TXT.',
            'file.max' => 'Dokumen melebihi batas yang diizinkan.',
            'file.required' => 'Pilih sebuah dokumen untuk diunggah.',
        ];
    }
}
