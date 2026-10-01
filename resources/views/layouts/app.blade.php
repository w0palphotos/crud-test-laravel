<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>@yield('title', 'Products') &middot; {{ config('app.name') }}</title>

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-neutral-50 text-neutral-900 antialiased">
        <header class="border-b border-neutral-200 bg-white">
            <div class="mx-auto flex max-w-5xl items-center justify-between gap-4 px-4 py-3 sm:px-6">
                <a href="{{ route('products.index') }}" class="text-sm font-semibold tracking-tight">
                    {{ config('app.name') }}
                </a>

                @auth
                    <nav class="flex items-center gap-1 text-sm" aria-label="Main">
                        <a href="{{ route('products.index') }}"
                           @class([
                               'rounded-md px-3 py-1.5 font-medium',
                               'bg-neutral-900 text-white' => request()->routeIs('products.*'),
                               'text-neutral-600 hover:bg-neutral-100' => ! request()->routeIs('products.*'),
                           ])>Products</a>

                        <a href="{{ route('account.show') }}"
                           @class([
                               'rounded-md px-3 py-1.5 font-medium',
                               'bg-neutral-900 text-white' => request()->routeIs('account.*'),
                               'text-neutral-600 hover:bg-neutral-100' => ! request()->routeIs('account.*'),
                           ])>{{ auth()->user()->name }}</a>

                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="rounded-md px-3 py-1.5 font-medium text-neutral-600 hover:bg-neutral-100">
                                Sign out
                            </button>
                        </form>
                    </nav>
                @endauth
            </div>
        </header>

        <main class="mx-auto max-w-5xl px-4 py-8 sm:px-6">
            @if (session('status'))
                <div role="status" class="mb-6 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                    {{ session('status') }}
                </div>
            @endif

            @yield('content')
        </main>
    </body>
</html>
