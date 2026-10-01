{{-- Shared by the create and edit forms so validation markup lives in one place. --}}
@php
    // create.blade.php has no $product at all, edit.blade.php does, so normalise
    // it rather than assuming either. An include does not define a variable the
    // parent never had.
    $product = $product ?? null;
    $editing = $product !== null;
@endphp

<div class="space-y-4">
    <div>
        <label for="name" class="block text-sm font-medium">Name</label>
        <input id="name" name="name" type="text" value="{{ old('name', $product?->name) }}" required maxlength="255"
               @class([
                   'mt-1.5 w-full rounded-md border px-3 py-2 text-sm',
                   'border-rose-400' => $errors->has('name'),
                   'border-neutral-300' => ! $errors->has('name'),
               ])>
        @error('name')<p class="mt-1.5 text-sm text-rose-600">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="description" class="block text-sm font-medium">Description</label>
        <textarea id="description" name="description" rows="3" maxlength="1000"
                  @class([
                      'mt-1.5 w-full rounded-md border px-3 py-2 text-sm',
                      'border-rose-400' => $errors->has('description'),
                      'border-neutral-300' => ! $errors->has('description'),
                  ])>{{ old('description', $product?->description) }}</textarea>
        @error('description')<p class="mt-1.5 text-sm text-rose-600">{{ $message }}</p>@enderror
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <label for="price" class="block text-sm font-medium">Price</label>
            <input id="price" name="price" type="text" inputmode="decimal" required
                   value="{{ old('price', $product ? number_format((float) $product->price, 2, '.', '') : '') }}"
                   placeholder="0.00"
                   @class([
                       'mt-1.5 w-full rounded-md border px-3 py-2 text-sm tabular-nums',
                       'border-rose-400' => $errors->has('price'),
                       'border-neutral-300' => ! $errors->has('price'),
                   ])>
            @error('price')<p class="mt-1.5 text-sm text-rose-600">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="stock" class="block text-sm font-medium">Stock</label>
            <input id="stock" name="stock" type="number" min="0" step="1" required
                   value="{{ old('stock', $product?->stock ?? 0) }}"
                   @class([
                       'mt-1.5 w-full rounded-md border px-3 py-2 text-sm tabular-nums',
                       'border-rose-400' => $errors->has('stock'),
                       'border-neutral-300' => ! $errors->has('stock'),
                   ])>
            @error('stock')<p class="mt-1.5 text-sm text-rose-600">{{ $message }}</p>@enderror
        </div>
    </div>
</div>

<div class="mt-6 flex items-center gap-2">
    <button type="submit" class="rounded-md bg-neutral-900 px-3 py-2 text-sm font-medium text-white hover:bg-neutral-700">
        {{ $editing ? 'Save changes' : 'Create product' }}
    </button>

    <a href="{{ route('products.index') }}" class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium hover:bg-neutral-100">
        Cancel
    </a>
</div>
