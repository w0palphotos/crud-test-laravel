@extends('layouts.app')

@section('title', 'Products')

@section('content')
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-lg font-semibold tracking-tight">Products</h1>

        <a href="{{ route('products.create') }}"
           class="rounded-md bg-neutral-900 px-3 py-2 text-sm font-medium text-white hover:bg-neutral-700">
            New product
        </a>
    </div>

    <form method="GET" action="{{ route('products.index') }}" class="mt-6 flex gap-2" role="search">
        <label for="q" class="sr-only">Search by name</label>
        <input id="q" name="q" type="search" value="{{ $search }}" placeholder="Search by name"
               class="w-full max-w-xs rounded-md border border-neutral-300 px-3 py-2 text-sm">
        <button type="submit" class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium hover:bg-neutral-100">
            Search
        </button>
        @if ($search !== '')
            <a href="{{ route('products.index') }}" class="px-2 py-2 text-sm text-neutral-600 hover:underline">Clear</a>
        @endif
    </form>

    @if ($products->isEmpty())
        <p class="mt-8 rounded-md border border-dashed border-neutral-300 px-4 py-10 text-center text-sm text-neutral-600">
            {{ $search === '' ? 'No products yet.' : "No products match \"{$search}\"." }}
        </p>
    @else
        <div class="mt-6 overflow-hidden rounded-lg border border-neutral-200 bg-white">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">Products</caption>
                <thead class="border-b border-neutral-200 bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-medium">Name</th>
                        <th scope="col" class="px-4 py-3 font-medium">Price</th>
                        <th scope="col" class="px-4 py-3 font-medium">Stock</th>
                        <th scope="col" class="px-4 py-3"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100">
                    @foreach ($products as $product)
                        <tr>
                            <th scope="row" class="px-4 py-3 font-medium">
                                {{ $product->name }}
                                @if ($product->description)
                                    <span class="mt-0.5 block text-xs font-normal text-neutral-500 line-clamp-2">
                                        {{ $product->description }}
                                    </span>
                                @endif
                            </th>
                            <td class="px-4 py-3 tabular-nums">{{ number_format((float) $product->price, 2) }}</td>
                            <td class="px-4 py-3 tabular-nums">{{ $product->stock }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-1">
                                    <a href="{{ route('products.edit', $product) }}"
                                       class="rounded-md px-2 py-1 text-sm font-medium text-neutral-700 hover:bg-neutral-100">Edit</a>

                                    {{-- Alpine holds the confirmation so a stray click cannot delete. --}}
                                    <div x-data="{ open: false }">
                                        <button type="button" @click="open = true"
                                                class="rounded-md px-2 py-1 text-sm font-medium text-rose-600 hover:bg-rose-50">
                                            Delete
                                        </button>

                                        <div x-show="open" x-cloak
                                             class="fixed inset-0 flex items-center justify-center bg-neutral-900/40 p-4"
                                             @keydown.escape.window="open = false">
                                            <div class="w-full max-w-sm rounded-lg bg-white p-5 shadow-xl"
                                                 @click.outside="open = false">
                                                <p class="text-sm">
                                                    Delete <span class="font-semibold">{{ $product->name }}</span>?
                                                    This cannot be undone.
                                                </p>
                                                <div class="mt-5 flex justify-end gap-2">
                                                    <button type="button" @click="open = false"
                                                            class="rounded-md border border-neutral-300 px-3 py-1.5 text-sm font-medium hover:bg-neutral-100">
                                                        Cancel
                                                    </button>
                                                    <form method="POST" action="{{ route('products.destroy', $product) }}">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit"
                                                                class="rounded-md bg-rose-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-rose-700">
                                                            Delete
                                                        </button>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $products->links() }}</div>
    @endif
@endsection
