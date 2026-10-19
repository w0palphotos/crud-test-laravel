<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * The gallery follows the same rule as every other field here: `sometimes`,
     * which is what makes this a partial update. A request without `images` leaves
     * the gallery alone.
     *
     * A request that *does* carry `images` must carry at least one. The two used to
     * be told apart — absent meaning untouched and `[]` meaning emptied — but a
     * product with no picture is a hole in the catalogue, and emptying is the only
     * way to make one through this form. So the empty list is refused, and absence
     * is the only way left to say "leave the gallery as it is".
     *
     * The id rule is scoped to this product, unlike the store request's, because the
     * route is the only place the product is known. Without that scope the search
     * would accept any row in the table and the action would be left to refuse it.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'price' => ['sometimes', 'required', 'numeric', 'decimal:0,2', 'min:0'],
            'stock' => ['sometimes', 'required', 'integer', 'min:0'],

            'images' => ['sometimes', 'array', 'min:1'],
            'images.*.id' => [
                'sometimes',
                'integer',
                Rule::exists('product_images', 'id')->where('product_id', $this->route('product')->id),
            ],
            'images.*.path' => ['sometimes', 'string', 'starts_with:uploads/', $this->anUploadedFile()],
            'images.*.alt_text' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'images.min' => 'Produk perlu minimal satu gambar.',
        ];
    }

    /**
     * Whether the disk is actually holding the file the row names.
     *
     * @see StoreProductRequest for why this is a closure and not a rule object.
     */
    private function anUploadedFile(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! Storage::disk(config('images.disk'))->exists($value)) {
                $fail('Berkas gambar tidak ditemukan. Unggah ulang.');
            }
        };
    }
}
