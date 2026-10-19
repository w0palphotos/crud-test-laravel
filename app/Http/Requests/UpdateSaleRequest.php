<?php

namespace App\Http\Requests;

/**
 * Amending an existing sale.
 *
 * The rules are StoreSaleRequest's, inherited rather than copied, because an edit
 * posts the same fields for the same reasons and a second copy of seventy lines of
 * rules would be a second thing to keep in step — the note ceiling, the reason
 * there is no character limit on rich text, the line-by-line product check and the
 * Indonesian messages all exist once.
 *
 * This is deliberately not a partial update the way UpdateProductRequest is. A
 * product is amended a field at a time and an omitted key means "leave that alone".
 * A sale's lines are a whole set: the totals are computed from all of them, so an
 * absent `items` would have to mean "reuse what is already stored" — a second rule
 * to infer from the shape of a payload, and the one inference that could leave a
 * header describing items the request never mentioned. The form posts everything
 * every time, which is what lets UpdateSale treat the payload as the whole truth.
 */
class UpdateSaleRequest extends StoreSaleRequest
{
    //
}
