<?php

namespace App\Http\Requests;

use App\Rules\AccountValidation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSupplierRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('contact_email')) {
            $this->merge([
                'contact_email' => AccountValidation::normaliseEmail($this->input('contact_email')),
            ]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Partial update, so any field left out keeps its current value. Name
     * uniqueness ignores this supplier: keeping the same name is not taking
     * someone else's.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('suppliers', 'name')->ignore($this->route('supplier')->id)],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:24'],
        ];
    }
}
