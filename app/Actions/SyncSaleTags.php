<?php

namespace App\Actions;

use App\Models\Sale;
use App\Models\User;

/**
 * Replaces a sale's tags with the set a form submitted.
 *
 * Shared by CreateSale and UpdateSale so that "the list the form posts is the
 * complete intended set" is one rule rather than two copies of it.
 *
 * Sync is used rather than attach because attach only ever adds, so removing a tag
 * in the interface would appear to do nothing.
 */
class SyncSaleTags
{
    /**
     * An empty list clears the tags, on an edit as much as on a create.
     *
     * The two cases are not distinguished: a brand new sale has no tags to clear,
     * so sync([]) is already a no-op there, and branching on it would only create
     * a shape an edit must not have.
     *
     * @param  array<int, array{id: int, note?: string|null}|int>  $tags
     */
    public function handle(Sale $sale, array $tags, ?User $actor = null): void
    {
        $payload = [];

        foreach ($tags as $tag) {
            // A tag may arrive as a bare id or as {id, note}: the note is what
            // makes this pivot carry data, and a plain multiselect can only send
            // the id.
            $payload[is_array($tag) ? $tag['id'] : $tag] = [
                'note' => is_array($tag) ? ($tag['note'] ?? null) : null,
                'user_id' => $actor?->id,
            ];
        }

        $sale->tags()->sync($payload);
    }
}
