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
     * A plain redirect to /dashboard would be right for an administrator and
     * wrong for everyone else: an ordinary account would arrive to a 403, which
     * reads as a broken link rather than as "this is not yours". Their landing
     * page is the account page, the one page every signed-in account can open.
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
