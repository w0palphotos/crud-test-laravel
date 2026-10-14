<?php

namespace App\Http\Requests;

use App\Enums\SaleStatus;
use App\Models\Sale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Moving a sale to a new status.
 *
 * There is deliberately no "update the sale" request. A sale is a financial
 * record: once its lines are agreed they are what the customer was quoted, and
 * editing them afterwards would mean the stored totals and the items they came
 * from could disagree with no trace of which one was right. The only part that
 * legitimately moves is where the sale is in its lifecycle, and every change to
 * that is written to sale_status_events.
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
