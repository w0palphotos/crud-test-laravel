<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>Sign in &middot; {{ config('app.name') }}</title>

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="flex min-h-screen items-center justify-center bg-neutral-50 px-4 text-neutral-900 antialiased">
        <div class="w-full max-w-sm">
            <h1 class="text-lg font-semibold tracking-tight">Sign in</h1>
            <p class="mt-1 text-sm text-neutral-600">
                Accounts are created through the API, not here.
            </p>

            <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-4">
                @csrf

                <div>
                    <label for="email" class="block text-sm font-medium">Email</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus
                           autocomplete="username"
                           @class([
                               'mt-1.5 w-full rounded-md border px-3 py-2 text-sm',
                               'border-rose-400' => $errors->has('email'),
                               'border-neutral-300' => ! $errors->has('email'),
                           ])>
                    @error('email')
                        <p class="mt-1.5 text-sm text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium">Password</label>
                    <input id="password" name="password" type="password" required
                           autocomplete="current-password"
                           @class([
                               'mt-1.5 w-full rounded-md border px-3 py-2 text-sm',
                               'border-rose-400' => $errors->has('password'),
                               'border-neutral-300' => ! $errors->has('password'),
                           ])>
                    @error('password')
                        <p class="mt-1.5 text-sm text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                <label class="flex items-center gap-2 text-sm text-neutral-700">
                    <input type="checkbox" name="remember" value="1"
                           class="size-4 rounded border-neutral-300">
                    Stay signed in
                </label>

                <button type="submit" class="w-full rounded-md bg-neutral-900 px-3 py-2 text-sm font-medium text-white hover:bg-neutral-700">
                    Sign in
                </button>
            </form>

            <p class="mt-6 text-sm text-neutral-600">
                <a href="/api/documentation" class="underline underline-offset-4">API documentation</a>
            </p>
        </div>
    </body>
</html>
