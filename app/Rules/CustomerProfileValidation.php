<?php

namespace App\Rules;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The one definition of a customer profile's fields, used by every door that
 * accepts one.
 *
 * The counterpart to AccountValidation for the commercial side, and for the same
 * reason. A profile is written from three places — the profile form on the edit
 * page, the create form that carries a profile alongside the customer, and the
 * API's two customer endpoints — and each of them is a way to reach the same five
 * columns. Left to their own devices they drift, and the drift here is not a wrong
 * label: two of the columns are NOT NULL with a default, so a rule that treats a
 * blank as "nothing" writes an explicit NULL and the insert raises a constraint
 * violation. A blank credit limit is a 500, not a zero.
 *
 * So the fields, their rules and their blanks live here, and the callers name
 * them.
 */
final class CustomerProfileValidation
{
    /**
     * What an untouched optional field stores.
     *
     * These are the two columns the migration declares NOT NULL with a default:
     * `credit_limit` defaults to 0 and `payment_terms` to 'COD'. Writing the
     * default is the only value that means "not decided", and it is also the
     * value the database itself would store had the key been left out — so
     * resolving blanks to these keeps the form and the schema telling the same
     * story. The other three fields are nullable, so a blank there means null and
     * is left alone.
     *
     * @var array<string, mixed>
     */
    public const BLANK = [
        'credit_limit' => 0,
        'payment_terms' => 'COD',
    ];

    /**
     * The five profile fields.
     *
     * The customer is never among them: it is taken from the route, not the body,
     * because the profile belongs to exactly one customer. Accepting a
     * customer_id here would be a second way to say who this is, and a way a
     * request could answer differently from the URL it was posted to.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'city_id' => ['nullable', 'integer', Rule::exists('cities', 'id')],
            'address' => ['nullable', 'string', 'max:500'],

            'tax_id' => [
                'nullable',
                'string',
                'max:32',

                // Dots and dashes are what an NPWP is written with. Not digits
                // alone, because the format people copy from their tax
                // certificate always carries them.
                'regex:/^[0-9.\- ]+$/',
            ],

            // decimal:0,2, matching the column. max:0.2 caps the amount at two
            // billion, past which decimal(14,2) would overflow the column and
            // fail at insert time with a driver error instead of a message.
            'credit_limit' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:2000000000'],

            'payment_terms' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * The field names a person reads, for messages the rule cannot phrase.
     *
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'tax_id' => 'tax number',
            'credit_limit' => 'credit limit',
        ];
    }

    /**
     * Resolve the blanks of whichever fields this request actually carries.
     *
     * Only for a key that is present and null. A key that was never sent means the
     * caller is not talking about that field — a partial update leaves it alone —
     * and filling it in would turn "I changed the address" into "and I set their
     * credit limit to zero". The distinction between absent and blank is the same
     * one ProductController keeps between `has('images')` and an empty array, and
     * it is the difference between a PATCH and a PUT.
     */
    public static function applyBlanks(FormRequest $request): void
    {
        foreach (self::BLANK as $field => $default) {
            if ($request->exists($field) && $request->input($field) === null) {
                $request->merge([$field => $default]);
            }
        }
    }

    /**
     * The profile's five columns, taken out of a whole validated request.
     *
     * Narrowing rather than passing the array through, because StoreCustomerRequest
     * validates the customer's three columns alongside these five and writing
     * `name` into `customer_profiles` is not a thing the mass-assignment guard
     * would save us from — `#[Fillable]` would drop it silently, which is the
     * quiet failure mode this class exists to prevent.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function payload(array $validated): array
    {
        return array_intersect_key($validated, self::rules());
    }
}
