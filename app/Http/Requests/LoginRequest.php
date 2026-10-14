<?php

namespace App\Http\Requests;

use App\Rules\AccountValidation;
use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    /**
     * Canonicalise the email, so signing in is case-insensitive in exactly the
     * way registration and the unique index are.
     *
     * Without this, an account stored as "ada@example.com" could not be reached by
     * typing the address as it was first entered — the person would be told their
     * credentials were wrong for the specific crime of typing their own address
     * with a capital letter.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => AccountValidation::normaliseEmail($this->input('email')),
        ]);
    }

    /**
     * Credential shape only, never the creation policy.
     *
     * There is deliberately no strength rule here. This validates that an email
     * and a password were supplied so an account can be looked up; whether that
     * password was ever strong enough to set is settled when it is set. Applying
     * the creation policy at sign-in would lock out every account created under
     * an older policy, on the grounds that a password they cannot change is a
     * problem they are being asked to log in to report.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }
}
