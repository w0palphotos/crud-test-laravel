<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * The gallery travels with the product rather than through an endpoint of its
     * own, the way a sale's tags travel with the sale: the form means "this product,
     * with these images", and splitting that into two requests would turn one Save
     * into two things to report separately when it fails.
     *
     * A row is either an image the product already has (`id`) or a file the upload
     * endpoint has just accepted (`path`). Both are checked against what this
     * application actually holds: an id must be a real row, and a path must be a file
     * the image disk is keeping. A path is a string a client can type, and a row
     * pointing at a file nobody ever uploaded is a gallery entry no one can show.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'price' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
            'stock' => ['required', 'integer', 'min:0'],

            'images' => ['nullable', 'array'],
            'images.*.id' => ['sometimes', 'integer', Rule::exists('product_images', 'id')],
            'images.*.path' => ['sometimes', 'string', 'starts_with:uploads/', $this->anUploadedFile()],
            'images.*.alt_text' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Whether the disk is actually holding the file the row names.
     *
     * A closure rather than a rule object because it needs the configured disk, and
     * the disk is configuration: which one serves images is the deployment's
     * business, not this request's.
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
