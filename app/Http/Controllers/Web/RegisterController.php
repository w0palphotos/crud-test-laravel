<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterAccountRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class RegisterController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    /**
     * Create an account and sign it in.
     *
     * Validation is RegisterAccountRequest, the same class POST /api/account uses,
     * so the two doors cannot drift on what a valid account is. The rules also
     * arrive with the field names already filled in, so old input survives a
     * failed attempt.
     *
     * The password is hashed by the User model's `hashed` cast, and the route is
     * throttled to match POST /api/account, so this form is exactly as reachable
     * as the endpoint it mirrors. It is the same exposure with a nicer interface.
     */
    public function store(RegisterAccountRequest $request): RedirectResponse
    {
        $user = User::create($request->validated());

        Auth::login($user);

        // A registration hands the browser a new authenticated session, so this is
        // a privilege change and needs a fresh id for the same reason login does.
        $request->session()->regenerate();

        return redirect()->intended(route('products.index'));
    }
}
