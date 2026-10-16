<?php

namespace App\Http\Requests;

use App\Rules\AccountValidation;
use App\Rules\CustomerProfileValidation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A new customer, and the commercial profile that arrives with them.
 *
 * One request rather than two because the create form is one form: a person adding
 * a customer has their name, their city and their payment terms in front of them
 * at the same time, and splitting the submission would mean two requests to fail
 * independently and a half-made customer left behind when the second one refuses.
 * The profile's fields still come from CustomerProfileValidation, so the rules and
 * the blanks are the same ones the profile form writes.
 *
 * The columns still live in two tables. A customer exists from the moment they
 * order once and the profile is written when the desk onboards them; this request
 * happens to write both in one go, which is a statement about the form, not about
 * the schema.
 */
class StoreCustomerRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => AccountValidation::normaliseEmail($this->input('email')),
        ]);

        CustomerProfileValidation::applyBlanks($this);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],

            // The same normalise-then-compare order as every other email this
            // application stores, so the unique index is asked about the value
            // that would actually be written.
            'email' => [
                ...AccountValidation::emailRules(),
                Rule::unique('customers', 'email'),
            ],

            'phone' => ['nullable', 'string', 'max:24'],

            ...CustomerProfileValidation::rules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return CustomerProfileValidation::attributes();
    }
}
