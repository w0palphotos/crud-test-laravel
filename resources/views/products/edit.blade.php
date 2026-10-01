@extends('layouts.app')

@section('title', 'Edit ' . $product->name)

@section('content')
    <h1 class="text-lg font-semibold tracking-tight">Edit product</h1>

    <form method="POST" action="{{ route('products.update', $product) }}"
          class="mt-6 max-w-xl rounded-lg border border-neutral-200 bg-white p-5">
        @csrf
        @method('PATCH')

        @include('products._form')
    </form>
@endsection
