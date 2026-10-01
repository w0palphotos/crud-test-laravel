<?php

namespace App\Actions;

use App\Models\User;

class DeleteAccount
{
    /**
     * Delete the account, revoking its tokens first.
     *
     * Shared by the API and the web UI so the token cleanup exists once. This is
     * security-relevant: personal_access_tokens has no ON DELETE CASCADE and
     * Sanctum registers no deleting hook, so skipping the explicit delete would
     * leave a valid bearer token pointing at a row that no longer exists.
     *
     * A leaked token is worse than a leaked password, because the password can be
     * changed and the token cannot be found or revoked once its owner is gone.
     *
     * The caller must invalidate any session and forget the remember token before
     * calling this. Auth::logout() saves the user model when it cycles a non-empty
     * remember token, and saving a deleted model re-inserts the row.
     */
    public function handle(User $user): void
    {
        $user->tokens()->delete();
        $user->delete();
    }
}
