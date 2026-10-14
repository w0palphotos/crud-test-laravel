<?php

namespace App\Http\Requests;

use App\Rules\AccountValidation;
use Illuminate\Foundation\Http\FormRequest;

class RegisterAccountRequest extends FormRequest
{
    /**
     * Canonicalise the email before it is validated, so uniqueness is checked
     * against the value that will be stored rather than the value that was
     * typed. See AccountValidation::normaliseEmail for why that matters.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => AccountValidation::normaliseEmail($this->input('email')),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * The email and password rules come from AccountValidation, which is also
     * what PATCH /api/account, POST /api/auth/token and the admin:ensure command
     * use. Five doors, one definition, so a door cannot quietly accept something
     * the others refuse.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => AccountValidation::uniqueEmailRules(),
            'password' => ['required', 'confirmed', AccountValidation::password()],
        ];
    }
}
