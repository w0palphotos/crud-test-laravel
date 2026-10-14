<?php

namespace App\Http\Requests;

use App\Rules\AccountValidation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountRequest extends FormRequest
{
    /**
     * Canonicalise the email before it is validated, for the same reason
     * registration does: uniqueness must be checked against the stored form.
     */
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
     * Rotating a password is deliberately gated on the caller proving they know
     * the existing one, so a leaked bearer token cannot lock the owner out. The
     * gate is conditional rather than `required_with:password`, which only
     * governs presence: without it a stray `current_password` sent alongside an
     * unrelated change would still be checked and fail.
     *
     * `different:current_password` rejects a rotation that reuses the password
     * being replaced. Sending the same value twice is not a rotation, and
     * accepting it would report a password change — and revoke every token — for
     * a password that did not change. The rule is inert when no password is being
     * rotated, because there is no current_password field to compare against.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', ...AccountValidation::uniqueEmailRules($this->user()?->getKey())],
            'current_password' => [Rule::when($this->filled('password'), ['required', 'current_password'])],
            'password' => [
                'sometimes',
                'required',
                'confirmed',
                'different:current_password',
                AccountValidation::password(),
            ],
        ];
    }
}
