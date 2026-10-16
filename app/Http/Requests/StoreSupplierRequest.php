<?php

namespace App\Http\Requests;

use App\Rules\AccountValidation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A new supplier.
 *
 * The counterpart of UpdateSupplierRequest with `sometimes` dropped and `ignore`
 * taken off the unique rule: there is no row yet to be the same as. Email is
 * normalised before it is compared so the index is asked about the value that
 * would be written — see AccountValidation::normaliseEmail.
 */
class StoreSupplierRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'contact_email' => AccountValidation::normaliseEmail($this->input('contact_email')),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('suppliers', 'name')],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:24'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'contact_email' => 'contact email',
        ];
    }
}
