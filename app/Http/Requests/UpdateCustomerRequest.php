<?php

namespace App\Http\Requests;

use App\Rules\AccountValidation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomerRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge([
                'email' => AccountValidation::normaliseEmail($this->input('email')),
            ]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Partial update, so any field left out keeps its current value. Email
     * uniqueness ignores this customer: keeping the same address is not taking
     * someone else's.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('customers', 'email')->ignore($this->route('customer')->id)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:24'],
        ];
    }
}
