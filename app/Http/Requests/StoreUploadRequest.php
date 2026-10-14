<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreUploadRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * The limits and the allow list both come from config/images.php rather than
     * being written here, so the endpoint, the docs and anything generating a
     * client-side hint all read the same numbers and cannot drift apart.
     *
     * Both `mimes` and `mimetypes` read the bytes: `mimes` resolves through
     * guessExtension() and `mimetypes` through getMimeType(), and neither trusts
     * the client's filename. That was checked by swapping one for the other and
     * watching the suite stay green — an earlier draft of this file claimed
     * otherwise.
     *
     * `mimetypes` is used anyway so the rule is written in the same vocabulary as
     * config/images.php. `mimes` would need a second list, of extensions, that
     * could drift from the MIME list beside it — and the SVG exclusion below is
     * exactly the kind of thing that quietly stops applying when two lists
     * disagree.
     *
     * There is deliberately no `dimensions` rule. It needs GD, and the runtime
     * image installs only intl, zip and pdo_pgsql — so the rule would pass
     * everywhere else and fail on the first upload in production. Adding GD for
     * it is not obviously a good trade either: it is an image parser, which is
     * its own attack surface, to defend against a decompression bomb that the
     * 2 MB cap above already bounds to something small enough to measure.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $allowed = config('images.mimetypes');
        $maxKilobytes = (int) config('images.max_kilobytes');

        return [
            'image' => [
                'required',
                'file',
                'mimetypes:'.implode(',', $allowed),
                "max:{$maxKilobytes}",
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $maxKilobytes = (int) config('images.max_kilobytes');

        return [
            'image.mimetypes' => 'Gambar harus berupa JPG, PNG, atau WebP.',
            'image.max' => "Gambar tidak boleh lebih besar dari {$maxKilobytes} KB.",
            'image.required' => 'Pilih sebuah gambar untuk diunggah.',
        ];
    }
}
