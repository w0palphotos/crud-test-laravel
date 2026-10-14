<?php

namespace App\Http\Requests;

use App\Enums\SaleStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSaleRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * The items are validated as a nested array rather than one field at a time,
     * because "line 3 has a bad quantity" is only expressible if the lines are
     * addressed individually. `items.*.quantity` is what makes the message name
     * the line, and it is why the form can point at the row that is wrong instead
     * of refusing the whole submission.
     *
     * Nothing here checks the arithmetic. line_total, the discount and the
     * running totals are computed by CreateSale, because a total sent by a
     * browser is not a total anyone should store.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')],
            'salesperson_id' => ['required', 'integer', Rule::exists('salespeople', 'id')],
            'city_id' => ['required', 'integer', Rule::exists('cities', 'id')],

            // Nullable so a back-dated or imported sale can be filed without
            // inventing a moment, in which case CreateSale uses now().
            'sold_at' => ['nullable', 'date'],

            'status' => ['nullable', Rule::enum(SaleStatus::class)],

            'discount_total' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],
            'tax_total' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],

            // No string or max rule on purpose. The content is rich text whose
            // length depends on how much of it is markup, so a character limit
            // would be counted against tags the user cannot see, and rejecting a
            // long-but-legitimate note for being over a limit set on prose is the
            // wrong trade. The ceiling that actually matters is on storage, and
            // this is the request side of that: an unbounded string is a cheap way
            // to fill a disk.
            //
            // CreateSale sanitises the result, which is where the tag allow-list
            // is enforced. Validating that here as well would mean two places to
            // keep in step for no gain — the purifier is the definition of what
            // this field may contain.
            'note' => ['nullable', 'string', 'max:200000'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => [
                'required',
                'integer',
                // exists, not the Product rule: the id must name a live row, and
                // the product's own price and stock are read by CreateSale rather
                // than accepted from here.
                Rule::exists('products', 'id'),
            ],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'items.*.unit_price' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
            'items.*.discount_total' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],

            // Tags may be ids or {id, note}: a plain multi-select can only send
            // the former, and the note is the pivot's own data.
            'tags' => ['nullable', 'array'],
            'tags.*' => ['nullable'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * The default for a nested field is "The items.0.quantity field is required",
     * which names an array index rather than the line the person was looking at.
     *
     * Indonesian, and hand-written rather than routed through lang/: these three
     * messages are specific to this form and would sit in the validation file's
     * custom block as dot paths, which is harder to find than the rules they sit
     * beside. The generic rules are translated in lang/id; anything phrased for
     * this particular form lives with the form.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => 'Penjualan minimal memiliki satu item.',
            'items.*.quantity.required' => 'Setiap baris perlu jumlah.',
            'items.*.unit_price.required' => 'Setiap baris perlu harga satuan.',
        ];
    }
}
