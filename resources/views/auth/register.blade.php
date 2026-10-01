<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>Create an account &middot; {{ config('app.name') }}</title>

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="flex min-h-screen items-center justify-center bg-neutral-50 px-4 text-neutral-900 antialiased">
        <div class="w-full max-w-sm">
            <h1 class="text-lg font-semibold tracking-tight">Create an account</h1>
            <p class="mt-1 text-sm text-neutral-600">
                It signs you in and gives you an account on the API.
            </p>

            <form method="POST" action="{{ route('register') }}" class="mt-6 space-y-4">
                @csrf

                <div>
                    <label for="name" class="block text-sm font-medium">Name</label>
                    <input id="name" name="name" type="text" value="{{ old('name') }}" required autofocus
                           autocomplete="name"
                           @class([
                               'mt-1.5 w-full rounded-md border px-3 py-2 text-sm',
                               'border-rose-400' => $errors->has('name'),
                               'border-neutral-300' => ! $errors->has('name'),
                           ])>
                    @error('name')
                        <p class="mt-1.5 text-sm text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="email" class="block text-sm font-medium">Email</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" required
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
                           autocomplete="new-password"
                           @class([
                               'mt-1.5 w-full rounded-md border px-3 py-2 text-sm',
                               'border-rose-400' => $errors->has('password'),
                               'border-neutral-300' => ! $errors->has('password'),
                           ])>
                    <p class="mt-1.5 text-sm text-neutral-600">At least 8 characters.</p>
                    @error('password')
                        <p class="mt-1.5 text-sm text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="password_confirmation" class="block text-sm font-medium">Confirm password</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" required
                           autocomplete="new-password"
                           class="mt-1.5 w-full rounded-md border border-neutral-300 px-3 py-2 text-sm">
                </div>

                <button type="submit" class="w-full rounded-md bg-neutral-900 px-3 py-2 text-sm font-medium text-white hover:bg-neutral-700">
                    Create account
                </button>
            </form>

            <p class="mt-6 text-sm text-neutral-600">
                Already registered?
                <a href="{{ route('login') }}" class="underline underline-offset-4">Sign in</a>
                &middot;
                <a href="/api/documentation" class="underline underline-offset-4">API documentation</a>
            </p>
        </div>
    </body>
</html>
