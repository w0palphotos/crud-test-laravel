<?php

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
     * The caller must invalidate any session and forget the remember token before
     * calling this. Auth::logout() saves the user model when it cycles a non-empty
     * remember token, and saving a deleted model re-inserts the row.
     *
     * @throws ValidationException When this is the last administrator.
     */
    public function handle(User $user): void
    {
        // The count, the decision and the delete are one transaction. Reading the
        // count in its own transaction would release the lock before the delete
        // ran, which is exactly the window two administrators deleting themselves
        // at the same moment would use to leave nobody behind.
        DB::transaction(function () use ($user): void {
            $this->refuseIfLastAdministrator($user);

            $user->tokens()->delete();
            $user->delete();
        });
    }

    /**
     * Refuse the deletion that would leave the deployment with no administrator.
     *
     * Recovery is genuinely expensive here: promotion happens through the
     * admin:ensure command in a shell, so an installation that has deleted its
     * last administrator has nobody who can log in and ask for one. Everything
     * else about an account — deleting it, changing its email, rotating its
     * password — stays as permissive as it was.
     *
     * @throws ValidationException
     */
    private function refuseIfLastAdministrator(User $user): void
    {
        if (! $user->is_admin) {
            return;
        }

        // The rows are locked and then counted in PHP, rather than counting in SQL
        // with the lock attached. Postgres rejects FOR UPDATE on an aggregate —
        // SQLSTATE 0A000, "FOR UPDATE is not allowed with aggregate functions" —
        // and SQLite ignores it silently, so the two disagree exactly where the
        // race would be decided. Selecting the ids locks the same rows and is
        // valid on both.
        $remaining = User::query()
            ->where('is_admin', true)
            ->lockForUpdate()
            ->pluck('id');

        if ($remaining->count() > 1) {
            return;
        }

        // ValidationException rather than a custom exception, because it already
        // knows how to answer on both surfaces: the web form redirects back with
        // a message and the API returns 422 with a body. A RuntimeException here
        // would surface as a 500 on both, which describes a server fault rather
        // than a refusal the person can act on.
        throw ValidationException::withMessages([
            'account' => 'Ini adalah satu-satunya administrator. '
                .'Jadikan akun lain administrator dengan `php artisan admin:ensure` terlebih dahulu.',
        ]);
    }
}
