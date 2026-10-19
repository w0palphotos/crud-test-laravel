/**
 * The pieces of a sale's form that both pages have to agree on.
 *
 * Kept out of the components so that a page can build its form before the
 * component that renders it exists. A blank line is the shape of a submitted row,
 * and a date input is a format — both are things two pages get wrong by copying
 * them and drifting, not by getting them wrong twice in the same way.
 */

/**
 * An empty line, with the keys the server validates.
 *
 * The keys are the contract, not a convenience: a line seeded without
 * `discount_total` posts a missing field, and the request reads it as no discount
 * rather than as an error.
 */
export function blankLine() {
    return {
        product_id: '',
        quantity: 1,
        unit_price: '',
        discount_total: '',
    };
}

/**
 * A local `YYYY-MM-DDTHH:mm` value, for a datetime-local input.
 *
 * `toISOString()` is UTC, which is the wrong clock twice over: it would move a
 * sale to the wrong day for anyone east of Greenwich in the evening, and it emits
 * a trailing `Z` that a datetime-local input silently drops anyway.
 */
export function localDateTime(date = new Date()) {
    const pad = (value) => String(value).padStart(2, '0');

    return [
        `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`,
        `${pad(date.getHours())}:${pad(date.getMinutes())}`,
    ].join('T');
}

/**
 * The three fields the browser used to enforce with `required` on a native select.
 *
 * A combobox draws its own input, and reka-ui's is not a control the HTML
 * constraint validator knows about, so the attribute stops doing anything. Left
 * uncaught, the first click of "Simpan" would go to the server purely to be told
 * what was already obvious — a round trip to show a message the form could have
 * shown immediately.
 *
 * The messages are the same words StoreSaleRequest uses, so a field rejected here
 * and a field rejected there read identically.
 */
const REQUIRED_DETAIL = [
    ['customer_id', 'Pelanggan wajib dipilih.'],
    ['salesperson_id', 'Sales wajib dipilih.'],
    ['city_id', 'Kota wajib dipilih.'],
];

/**
 * Whether the sale may be submitted, marking the fields that were left unchosen.
 *
 * Returns false rather than throwing, because the caller is a submit handler: a
 * false is a "stop here", and the page turns that into doing nothing at all.
 */
export function ensureDetailChosen(form) {
    const missing = REQUIRED_DETAIL.filter(([field]) => form[field] === '' || form[field] === null);

    for (const [field, message] of REQUIRED_DETAIL) {
        if (missing.some(([key]) => key === field)) {
            form.setError(field, message);
        } else {
            // Clear the ones that are now filled, so a message never outlives the
            // mistake it described.
            form.clearErrors(field);
        }
    }

    return missing.length === 0;
}