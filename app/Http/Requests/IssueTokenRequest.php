<?php

namespace App\Http\Requests;

use App\Rules\AccountValidation;
use Illuminate\Foundation\Http\FormRequest;

class IssueTokenRequest extends FormRequest
{
    /**
     * Canonicalise the email so the token endpoint looks up the same row the
     * registration stored, for the same reason LoginRequest does.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => AccountValidation::normaliseEmail($this->input('email')),
        ]);
    }

    /**
     * Credential shape only. The password is never created here, only checked, so
     * the creation policy from AccountValidation has no business at this door —
     * see LoginRequest for the same reasoning.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ];
    }
}
