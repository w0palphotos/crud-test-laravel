<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Amending the note on one entry in a sale's history.
 *
 * Only `note`, because only the note may change. The from/to columns say what the
 * sale was and what it became; a row that could rewrite those would no longer be
 * evidence of anything, and the transition itself was checked by whoever moved
 * the sale rather than by whoever annotated it afterwards.
 */
class UpdateSaleStatusEventRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Nullable as well as a string, so clearing the note is an intention
            // the validator accepts rather than a value it refuses. The limit is
            // the same 500 the textarea enforces, which is the size a sentence
            // about a delivery fits in; this field is not rich text and never was.
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
