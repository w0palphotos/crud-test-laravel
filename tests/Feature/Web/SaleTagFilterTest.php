<?php

namespace Tests\Feature\Web;

use App\Models\Sale;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The tag filter on the sales ledger.
 *
 * Filtering is reading, not editing, so nothing here writes: the tag rows and the
 * pivot attaching them are exercised by CreateSaleTest. What can go wrong here is
 * narrower and entirely about the query — a filter that ignores its input, one
 * that is read as "has every tag" when it was shown as a set of checkboxes, and
 * one that duplicates rows, which shows up as a ledger whose total disagrees with
 * the list above it.
 *
 * The last two are the reason these are asserted rather than eyeballed. Both
 * return plausible-looking pages.
 */
class SaleTagFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_tag_filter_keeps_only_the_sales_carrying_it(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $priority = Tag::factory()->create();
        $credit = Tag::factory()->create();

        $tagged = Sale::factory()->create();
        $tagged->tags()->attach($priority);

        Sale::factory()->create();
        $other = Sale::factory()->create();
        $other->tags()->attach($credit);

        $this->get("/sales?tag[]={$priority->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Sales/Index')
                ->has('sales.data', 1)
                ->where('sales.data.0.id', $tagged->id)
                ->where('filters.tags', [$priority->id])
            );
    }

    /**
     * Two checkboxes ticked means either tag, not both.
     *
     * And would answer a question nobody asked — a sale tagged priority+credit
     * would appear only when exactly those two were ticked, and a sale tagged
     * only one of them would vanish from a filter that showed it as selected.
     */
    public function test_two_chosen_tags_return_the_union_rather_than_the_intersection(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $priority = Tag::factory()->create();
        $credit = Tag::factory()->create();

        $first = Sale::factory()->create();
        $first->tags()->attach($priority);

        $second = Sale::factory()->create();
        $second->tags()->attach($credit);

        $both = Sale::factory()->create();
        $both->tags()->attach([$priority->id, $credit->id]);

        $this->get("/sales?tag[]={$priority->id}&tag[]={$credit->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('sales.data', 3)
                ->where('filters.tags', [$priority->id, $credit->id])
            );
    }

    /**
     * A sale holding both chosen tags is one row, not two.
     *
     * The join a naive filter would write returns it once per matching tag, which
     * would also make the paginator's total count higher than the number of rows
     * it is showing.
     */
    public function test_a_sale_carrying_both_chosen_tags_is_listed_once(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $priority = Tag::factory()->create();
        $credit = Tag::factory()->create();

        $both = Sale::factory()->create();
        $both->tags()->attach([$priority->id, $credit->id]);

        $response = $this->get("/sales?tag[]={$priority->id}&tag[]={$credit->id}")->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->has('sales.data', 1)
            ->where('sales.data.0.id', $both->id)
            ->where('sales.total', 1)
        );
    }

    public function test_no_tag_parameter_returns_the_whole_ledger(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Sale::factory()->count(3)->create();
        $one = Sale::factory()->create();
        $one->tags()->attach(Tag::factory()->create());

        $this->get('/sales')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('sales.data', 4)
                ->where('filters.tags', [])
            );
    }

    public function test_an_empty_tag_list_is_no_filter_at_all(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Sale::factory()->count(2)->create();

        $this->get('/sales?tag[]=')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('sales.data', 2)
                ->where('filters.tags', [])
            );
    }

    public function test_a_tag_id_that_matches_nothing_returns_an_empty_ledger(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $priority = Tag::factory()->create();

        $tagged = Sale::factory()->create();
        $tagged->tags()->attach($priority);

        $this->get('/sales?tag[]=999999')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('sales.data', 0)
            );
    }

    /**
     * The filter composes with the others rather than replacing them.
     *
     * Each narrows the same query, so ticking a tag and a status has to give the
     * intersection. A tag filter that reset the status would look correct in
     * isolation and quietly widen the ledger the moment two were used together.
     */
    public function test_the_tag_filter_narrows_the_same_query_as_the_status_filter(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $priority = Tag::factory()->create();

        $pending = Sale::factory()->create(['status' => 'pending']);
        $pending->tags()->attach($priority);

        $delivered = Sale::factory()->create(['status' => 'delivered']);
        $delivered->tags()->attach($priority);

        $this->get("/sales?tag[]={$priority->id}&status=pending")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('sales.data', 1)
                ->where('sales.data.0.id', $pending->id)
                ->where('filters.status', 'pending')
                ->where('filters.tags', [$priority->id])
            );
    }

    /**
     * The options come from the tags table, not from a list written out in the UI.
     */
    public function test_the_filter_offers_every_tag_with_its_colour(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Tag::factory()->create(['name' => 'credit', 'colour' => '#fef3c7']);
        Tag::factory()->create(['name' => 'priority', 'colour' => '#dbeafe']);

        $this->get('/sales')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('tags', 2)
                ->where('tags.0.name', 'credit')
                ->where('tags.0.colour', '#fef3c7')
                ->where('tags.1.name', 'priority')
                ->where('tags.1.colour', '#dbeafe')
            );
    }

    /**
     * Each row carries the labels of the tags it carries, so the ledger can show
     * them without a second lookup per row.
     *
     * The id alone would not do: the table renders the name and the colour, and
     * sending only the id would leave the component matching against the option
     * list to find them — which breaks silently for a tag that has since been
     * deleted from the table.
     */
    public function test_each_row_carries_its_own_tag_labels(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $priority = Tag::factory()->create(['name' => 'priority', 'colour' => '#dbeafe']);
        $credit = Tag::factory()->create(['name' => 'credit', 'colour' => '#fef3c7']);

        $sale = Sale::factory()->create();
        $sale->tags()->attach([$priority->id, $credit->id]);

        $this->get('/sales')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('sales.data.0.tags', 2)
                ->where('sales.data.0.tags.0.id', $priority->id)
                ->where('sales.data.0.tags.0.name', 'priority')
                ->where('sales.data.0.tags.0.colour', '#dbeafe')
            );
    }

    /**
     * A sale with no tags gets an empty list, not a missing key.
     *
     * The column iterates whatever arrives, so a sale that omitted the key would
     * render a cell out of nowhere instead of an empty one.
     */
    public function test_an_untagged_sale_carries_an_empty_tag_list(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Tag::factory()->create();

        $sale = Sale::factory()->create();

        $this->get('/sales')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('sales.data.0.id', $sale->id)
                ->where('sales.data.0.tags', [])
            );
    }

    /**
     * Ids arrive as strings from a query string and must not reach whereIn() raw.
     *
     * A hand-edited or pasted URL is the only way to produce one, and the failure
     * it would otherwise cause is a database type error on a page whose only
     * mistake was a character.
     */
    public function test_non_numeric_tag_ids_are_discarded_rather_than_queried(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Sale::factory()->count(2)->create();

        $this->get('/sales?tag[]=abc&tag[]=-1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('sales.data', 2)
                ->where('filters.tags', [])
            );
    }

    /**
     * The same set of tags must produce the same URL however it is ordered.
     *
     * Two people who ticked the same two boxes reach this ledger by different
     * routes, and a list whose links depend on the order the ids arrived in is
     * not a list whose links can be shared. The reported order is sorted, so
     * both routes produce one address.
     */
    public function test_the_chosen_tags_are_reported_in_a_stable_order(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        // Created low-to-high, then asked for high-to-low, so the expectation
        // cannot be satisfied by echoing the request back.
        $low = Tag::factory()->create();
        $high = Tag::factory()->create();

        $sale = Sale::factory()->create();
        $sale->tags()->attach([$low->id, $high->id]);

        $this->get("/sales?tag[]={$high->id}&tag[]={$low->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.tags', [$low->id, $high->id])
            );
    }
}
