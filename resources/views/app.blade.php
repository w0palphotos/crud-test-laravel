{{--
    The root template Inertia renders into. This is the only application Blade
    view: every page in the app is a Vue component resolved by Inertia, and this
    file is what carries the HTML document they are mounted into.

    It also serves the sign-in and registration forms, which are still
    server-rendered. That is a deliberate exception, not unfinished work: a
    sign-in page is the one place where a visitor should not have to wait for a
    bundle, and where a failed script would lock them out entirely.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name') }}</title>

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <x-inertia::head />
    </head>
    <body class="min-h-screen bg-neutral-50 text-neutral-900 antialiased">
        <x-inertia::app />
    </body>
</html>