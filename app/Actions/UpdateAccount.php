<?php

namespace App\Actions;

use App\Models\User;
use Illuminate\Http\Request;

class UpdateAccount
{
    /**
     * Apply a validated account update, rotating the password if one was supplied.
     *
     * Shared by the API and the web UI so the security-relevant part exists once.
     * Verifying `current_password` lives in UpdateAccountRequest as a conditional
     * rule, so by the time this runs the password is known to be authorised.
     *
     * Rotating a password revokes the account's other bearer tokens, so a
     * password change cannot leave a stolen token working. The caller's own token
     * survives. A session has no token, so under the web guard every token is
     * revoked, which is the stricter and correct outcome.
     *
     * @return bool Whether the password was rotated.
     */
    public function handle(Request $request, User $user): bool
    {
        $user->fill($request->safe()->except('current_password'));
        $rotated = $user->isDirty('password');
        $user->save();

        if ($rotated) {
            $currentTokenId = $request->user()->currentAccessToken()?->getKey();

            $user->tokens()
                ->when($currentTokenId, fn ($query) => $query->whereKeyNot($currentTokenId))
                ->delete();
        }

        return $rotated;
    }
}
