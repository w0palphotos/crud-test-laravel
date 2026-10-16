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
     * the gallery alone, and a request with `images: []` removes all of it — two
     * different intentions that would collapse into one if the key were required.
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

            'images' => ['nullable', 'array'],
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
