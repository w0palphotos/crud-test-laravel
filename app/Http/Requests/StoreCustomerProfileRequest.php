<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerProfileRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * The customer is taken from the route, not the body. The profile belongs to
     * exactly one customer, so accepting a customer_id here would be a second
     * way to say who this is — and a way a request could answer differently from
     * the URL it was posted to.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'city_id' => ['nullable', 'integer', Rule::exists('cities', 'id')],
            'address' => ['nullable', 'string', 'max:500'],

            'tax_id' => [
                'nullable',
                'string',
                'max:32',

                // Dots and dashes are what an NPWP is written with. Not digits
                // alone, because the format people copy from their tax
                // certificate always carries them.
                'regex:/^[0-9.\- ]+$/',
            ],

            // decimal:0,2, matching the column. max:0.2 caps the amount at two
            // billion, past which decimal(14,2) would overflow the column and
            // fail at insert time with a driver error instead of a message.
            'credit_limit' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:2000000000'],

            'payment_terms' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'tax_id' => 'tax number',
            'credit_limit' => 'credit limit',
        ];
    }
}
