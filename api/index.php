<?php

/*
|--------------------------------------------------------------------------
| Vercel Function entry point
|--------------------------------------------------------------------------
|
| Vercel invokes this file as a serverless function. It exists only to hand
| control to Laravel's own front controller, so the application boots from
| public/index.php exactly as it does behind Apache or nginx.
|
| The path is resolved with __DIR__ rather than a relative one so it does not
| depend on the working directory Vercel happens to use.
|
| This is only needed for the community vercel-php runtime. Vercel's own
| first-party guide uses Docker with FrankenPHP instead, and then this file is
| unnecessary. See docs/10-deploying.md.
|
*/

require __DIR__.'/../public/index.php';
