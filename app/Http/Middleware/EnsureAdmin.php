<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses any request from an account that is not an administrator.
 *
 * A separate middleware rather than a check inside each controller, because the
 * rule is "the whole desk is administrator-only" and twenty-odd controllers each
 * repeating it is twenty-odd places the rule could be forgotten in one. Declared
 * once on the route group, the boundary cannot drift as pages are added.
 *
 * 403 rather than a redirect: the caller is authenticated, so sending them to
 * the login form would ask them for a password they have already given. There is
 * a destination for an ordinary user, and the account page is it.
 *
 * Guests are not this middleware's problem. `auth` runs first, so by the time
 * this runs there is always a user, and a null here would mean the route was
 * mounted outside the auth group rather than that someone is anonymous.
 */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) $request->user()?->is_admin, 403);

        return $next($request);
    }
}
