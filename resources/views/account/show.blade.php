@extends('layouts.app')

@section('title', 'Account')

@section('content')
    <h1 class="text-lg font-semibold tracking-tight">Account</h1>

    <div class="mt-6 max-w-xl space-y-8">
        <form method="POST" action="{{ route('account.update') }}"
              class="rounded-lg border border-neutral-200 bg-white p-5">
            @csrf
            @method('PATCH')

            <h2 class="text-sm font-semibold">Details</h2>

            <div class="mt-4 space-y-4">
                <div>
                    <label for="name" class="block text-sm font-medium">Name</label>
                    <input id="name" name="name" type="text" value="{{ old('name', $user->name) }}" required maxlength="255"
                           @class([
                               'mt-1.5 w-full rounded-md border px-3 py-2 text-sm',
                               'border-rose-400' => $errors->has('name'),
                               'border-neutral-300' => ! $errors->has('name'),
                           ])>
                    @error('name')<p class="mt-1.5 text-sm text-rose-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="email" class="block text-sm font-medium">Email</label>
                    <input id="email" name="email" type="email" value="{{ old('email', $user->email) }}" required
                           autocomplete="username"
                           @class([
                               'mt-1.5 w-full rounded-md border px-3 py-2 text-sm',
                               'border-rose-400' => $errors->has('email'),
                               'border-neutral-300' => ! $errors->has('email'),
                           ])>
                    @error('email')<p class="mt-1.5 text-sm text-rose-600">{{ $message }}</p>@enderror
                </div>
            </div>

            <button type="submit" class="mt-5 rounded-md bg-neutral-900 px-3 py-2 text-sm font-medium text-white hover:bg-neutral-700">
                Save details
            </button>
        </form>

        <form method="POST" action="{{ route('account.update') }}"
              class="rounded-lg border border-neutral-200 bg-white p-5">
            @csrf
            @method('PATCH')

            <h2 class="text-sm font-semibold">Change password</h2>
            <p class="mt-1 text-sm text-neutral-600">
                Changing it revokes every API token on this account.
            </p>

            <div class="mt-4 space-y-4">
                <div>
                    <label for="current_password" class="block text-sm font-medium">Current password</label>
                    <input id="current_password" name="current_password" type="password" autocomplete="current-password"
                           @class([
                               'mt-1.5 w-full rounded-md border px-3 py-2 text-sm',
                               'border-rose-400' => $errors->has('current_password'),
                               'border-neutral-300' => ! $errors->has('current_password'),
                           ])>
                    @error('current_password')<p class="mt-1.5 text-sm text-rose-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="new_password" class="block text-sm font-medium">New password</label>
                    <input id="new_password" name="password" type="password" autocomplete="new-password"
                           @class([
                               'mt-1.5 w-full rounded-md border px-3 py-2 text-sm',
                               'border-rose-400' => $errors->has('password'),
                               'border-neutral-300' => ! $errors->has('password'),
                           ])>
                    @error('password')<p class="mt-1.5 text-sm text-rose-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="new_password_confirmation" class="block text-sm font-medium">Confirm new password</label>
                    <input id="new_password_confirmation" name="password_confirmation" type="password" autocomplete="new-password">
                </div>
            </div>

            <button type="submit" class="mt-5 rounded-md bg-neutral-900 px-3 py-2 text-sm font-medium text-white hover:bg-neutral-700">
                Change password
            </button>
        </form>

        <div x-data="{ open: false }"
             class="rounded-lg border border-rose-200 bg-rose-50 p-5">
            <h2 class="text-sm font-semibold text-rose-900">Delete account</h2>
            <p class="mt-1 text-sm text-rose-800">
                Removes your account and every API token it owns. This cannot be undone.
            </p>

            <button type="button" @click="open = true"
                    class="mt-4 rounded-md border border-rose-300 px-3 py-2 text-sm font-medium text-rose-700 hover:bg-rose-100">
                Delete account
            </button>

            <div x-show="open" x-cloak
                 class="fixed inset-0 flex items-center justify-center bg-neutral-900/40 p-4"
                 @keydown.escape.window="open = false">
                <div class="w-full max-w-sm rounded-lg bg-white p-5 shadow-xl" @click.outside="open = false">
                    <p class="text-sm">Delete <span class="font-semibold">{{ $user->name }}</span> permanently?</p>

                    <div class="mt-5 flex justify-end gap-2">
                        <button type="button" @click="open = false"
                                class="rounded-md border border-neutral-300 px-3 py-1.5 text-sm font-medium hover:bg-neutral-100">
                            Cancel
                        </button>
                        <form method="POST" action="{{ route('account.destroy') }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit"
                                    class="rounded-md bg-rose-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-rose-700">
                                Delete permanently
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
