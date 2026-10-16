<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterAccountRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class RegisterController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    /**
     * Create an account for someone else.
     *
     * Validation is RegisterAccountRequest, the same class POST /api/account uses,
     * so the two doors cannot drift on what a valid account is. The rules also
     * arrive with the field names already filled in, so old input survives a
     * failed attempt.
     *
     * The password is hashed by the User model's `hashed` cast.
     *
     * Registration always produces an ordinary account. is_admin is not
     * mass-assignable and is not among the validated fields, so there is no
     * request body that creates an administrator through this door; the only way
     * to be one is the admin:ensure command, run from a shell.
     *
     * Nobody is signed in here, which is the change from when this form was public.
     * Back then the person filling it in became the account, which was the whole
     * point. Now the person filling it in is an administrator handing a password
     * to somebody else, and signing them in as that account would switch the
     * administrator out of their own session to do it — the most confusing
     * possible outcome for a form that worked. The new account signs in for
     * itself, from the login page, with the password the administrator gave them.
     */
    public function store(RegisterAccountRequest $request): RedirectResponse
    {
        User::create($request->validated());

        return redirect()
            ->route('account.show')
            ->with('status', "Akun \"{$request->validated('email')}\" dibuat. Berikan kata sandinya, lalu mereka masuk sendiri.");
    }
}
