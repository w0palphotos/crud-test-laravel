@extends('layouts.app')

@section('title', 'New product')

@section('content')
    <h1 class="text-lg font-semibold tracking-tight">New product</h1>

    <form method="POST" action="{{ route('products.store') }}"
          class="mt-6 max-w-xl rounded-lg border border-neutral-200 bg-white p-5">
        @csrf

        @include('products._form')
    </form>
@endsection
