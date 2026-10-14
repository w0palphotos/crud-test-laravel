<?php

namespace App\Http\Controllers\Web;

use App\Actions\DeleteAccount;
use App\Actions\UpdateAccount;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateAccountRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    /**
     * The signed-in user's own settings, and nothing else. There is no route
     * through which one account reads or rewrites another's.
     *
     * @return array<string, mixed>
     */
    public function show(Request $request): Response
    {
        return Inertia::render('Account/Edit', ['user' => $request->user()]);
    }

    /**
     * Shares the password-rotation and token-revocation logic with the API
     * through the same action, so the two entry points cannot diverge on a
     * security-relevant rule.
     */
    public function update(UpdateAccountRequest $request, UpdateAccount $updateAccount): RedirectResponse
    {
        $rotated = $updateAccount->handle($request, $request->user());

        return redirect()
            ->route('account.show')
            ->with('status', $rotated
                ? 'Detail diperbarui. Kata sandi berubah, jadi seluruh token API dicabut.'
                : 'Detail diperbarui.');
    }

    public function destroy(Request $request, DeleteAccount $deleteAccount): RedirectResponse
    {
        $user = $request->user();

        // Log out before deleting, never after. Auth::logout() cycles a non-empty
        // remember token, which saves the model, and saving an already-deleted
        // model re-inserts the row, so the account would survive the deletion.
        Auth::logout();

        $deleteAccount->handle($user);

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
