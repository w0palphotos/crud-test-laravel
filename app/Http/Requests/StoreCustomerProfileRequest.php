<?php

namespace App\Http\Requests;

use App\Rules\CustomerProfileValidation;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A customer's commercial profile, written on its own.
 *
 * The five fields are declared in CustomerProfileValidation because three doors
 * accept them and only one of them is this request; see that class for why a blank
 * credit limit used to be a 500 rather than a zero.
 */
class StoreCustomerProfileRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        CustomerProfileValidation::applyBlanks($this);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return CustomerProfileValidation::rules();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return CustomerProfileValidation::attributes();
    }
}
