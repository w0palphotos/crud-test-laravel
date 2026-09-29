<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateAccountRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * Rotating a password is deliberately gated on the caller proving they know
     * the existing one, so a leaked bearer token cannot lock the owner out. The
     * gate is conditional rather than `required_with:password`, which only
     * governs presence: without it a stray `current_password` sent alongside an
     * unrelated change would still be checked and fail.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->user()->getKey())],
            'current_password' => [Rule::when($this->filled('password'), ['required', 'current_password'])],
            'password' => ['sometimes', 'required', 'confirmed', Password::min(8)],
        ];
    }
}
