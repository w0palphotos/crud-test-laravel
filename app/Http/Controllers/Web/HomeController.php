<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

/**
 * The landing page.
 *
 * A controller rather than a closure in routes/web.php, for one reason that has
 * nothing to do with tidiness: `php artisan route:cache` refuses to serialise a
 * route whose action is a Closure, so a single one in the file disables route
 * caching for the whole application. On a container that is paid for at every
 * cold start, because the route table has to be built from source instead of read
 * from `bootstrap/cache/routes.php`.
 *
 * It was the only such route. docs/10-deploying.md recorded the consequence as a
 * limitation to work around; this is the removal of it.
 */
class HomeController extends Controller
{
    /**
     * Send each account to the page that belongs to it.
     *
     * An administrator lands on the dashboard. An ordinary account lands on the
     * account page — it can open the dashboard too, but the account page is the
     * one that says what this account is rather than what the business sold.
     */
    public function __invoke(): RedirectResponse
    {
        $user = auth()->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        return redirect()->route($user->is_admin ? 'dashboard' : 'account.show');
    }
}
