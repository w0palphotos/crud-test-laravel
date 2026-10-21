<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class LoginController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Authenticate against the session guard.
     *
     * Accounts come from POST /register, which shares its validation and its rate
     * limit with POST /api/account.
     *
     * On a wrong password the message is attached to the email field rather than
     * thrown as a failed attempt, so the wording matches the API's TokenController
     * and a user who mistypes sees one message rather than two. The message is
     * auth.failed so both doors say it in the same language.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $credentials = $request->safe()->only(['email', 'password']);

        // Cap "remember me" at the same one-day login timeout. Laravel's default
        // remember cookie would otherwise outlive the session by months, and a
        // timeout that a checkbox can bypass is not a timeout.
        Auth::guard('web')->setRememberDuration((int) config('session.lifetime', 1440));

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended($this->landingFor($request));
    }

    public function destroy(Request $request): SymfonyResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Inertia::location rather than redirect(): /login is a Blade page, so a
        // standard Location header makes Inertia render the Blade HTML while
        // keeping the stale Inertia page state at /account. The X-Inertia-Location
        // header tells the frontend to do a full browser navigation instead.
        return Inertia::location(route('login'));
    }

    /**
     * Where this account belongs.
     *
     * `intended()` keeps a deep link working, but only when the guest was
     * actually heading somewhere. Its fallback mirrors the root route: an
     * administrator lands on the desk, an ordinary account on its own page.
     */
    private function landingFor(Request $request): string
    {
        return $request->user()->is_admin
            ? route('dashboard')
            : route('account.show');
    }
}
