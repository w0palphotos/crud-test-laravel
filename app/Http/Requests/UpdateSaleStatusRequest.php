<?php

namespace App\Http\Requests;

use App\Enums\SaleStatus;
use App\Models\Sale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Moving a sale to a new status.
 *
 * What may change on a sale is now split between two requests rather than settled
 * here. UpdateSaleRequest covers the commercial record — who it was sold to, on
 * what terms, for how much — and it recomputes every total from the lines so the
 * stored figures can never describe items the sale does not have. This request
 * covers only where the sale sits in its lifecycle, and every move it allows is
 * written to sale_status_events.
 *
 * The two are deliberately not merged into one "update the sale" form: a status
 * that could be set from anywhere would bypass the transition check below, and a
 * total that travelled with the form would be a number a browser chose. The
 * reference is not in either one — it is quoted to a customer over the phone, so
 * the number it was issued as has to stay the number it is.
 */
class UpdateSaleStatusRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => [
                'required',
                Rule::enum(SaleStatus::class),

                // Whether the move is legal is not a shape question but a state
                // question — it depends on where the sale already is — so it is
                // checked against the model's allowed next states.
                Rule::in($this->sale()?->status?->nextStates() ?? []),
            ],

            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.in' => 'Penjualan tidak dapat berpindah ke status tersebut dari status saat ini.',
        ];
    }

    /**
     * The sale being moved.
     *
     * Nullable rather than required: on a request that is failing for some other
     * reason there may be no sale to check, and the status rule above simply has
     * nothing to reject.
     */
    private function sale(): ?Sale
    {
        return $this->route('sale');
    }
}
