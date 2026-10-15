<?php

namespace Tests\Feature\Web;

use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The date range on the sales ledger.
 *
 * Filtering is reading, not editing, so nothing here writes. What can go wrong is
 * narrow and entirely about the boundary, and every version of it returns a page
 * that looks correct: a range whose closing day is half missing shows a quiet month
 * rather than an error, and an inverted range shows nothing at all, which is the
 * same page as a period in which nothing was sold. Those two are why this is
 * asserted rather than eyeballed.
 */
class SaleDateFilterTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): void
    {
        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_a_range_keeps_only_the_sales_filed_inside_it(): void
    {
        $this->actingAsAdmin();

        $inside = Sale::factory()->create(['sold_at' => '2026-03-10 09:00:00']);
        Sale::factory()->create(['sold_at' => '2026-03-09 23:59:00']);
        Sale::factory()->create(['sold_at' => '2026-03-11 00:01:00']);

        $this->get('/sales?from=2026-03-10&to=2026-03-10')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Sales/Index')
                ->has('sales.data', 1)
                ->where('sales.data.0.id', $inside->id)
                ->where('filters.from', '2026-03-10')
                ->where('filters.to', '2026-03-10')
            );
    }

    /**
     * The closing date means the whole of that day, not the instant it starts.
     *
     * sold_at is a timestamp, so the obvious `<= 2026-03-01` keeps only the first
     * second of the first of March and drops everything filed during it — the entire
     * closing day of the range, missing, with nothing on screen to say so.
     */
    public function test_the_closing_date_covers_the_whole_day(): void
    {
        $this->actingAsAdmin();

        $lastMinute = Sale::factory()->create(['sold_at' => '2026-03-01 23:59:00']);
        Sale::factory()->create(['sold_at' => '2026-03-02 00:00:00']);

        $this->get('/sales?to=2026-03-01')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('sales.data', 1)
                ->where('sales.data.0.id', $lastMinute->id)
            );
    }

    public function test_the_opening_date_covers_the_whole_day(): void
    {
        $this->actingAsAdmin();

        $midnight = Sale::factory()->create(['sold_at' => '2026-03-01 00:00:00']);
        Sale::factory()->create(['sold_at' => '2026-02-28 23:59:00']);

        $this->get('/sales?from=2026-03-01')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('sales.data', 1)
                ->where('sales.data.0.id', $midnight->id)
            );
    }

    /**
     * Typed the wrong way round, the pair is read in the only order that means
     * anything — and reported back ordered, so the inputs show what was understood.
     *
     * An empty ledger is the one answer that would be wrong here, because it is
     * indistinguishable from a period in which nothing was sold.
     */
    public function test_an_inverted_range_is_read_the_other_way_round(): void
    {
        $this->actingAsAdmin();

        $inside = Sale::factory()->create(['sold_at' => '2026-03-10 09:00:00']);
        Sale::factory()->create(['sold_at' => '2026-04-01 09:00:00']);

        $this->get('/sales?from=2026-03-31&to=2026-03-01')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('sales.data', 1)
                ->where('sales.data.0.id', $inside->id)
                ->where('filters.from', '2026-03-01')
                ->where('filters.to', '2026-03-31')
            );
    }

    /**
     * A parameter that does not name a day narrows nothing.
     *
     * The last two are the ones worth the case: `2026-3-1` is a day, written the way
     * a person writes one, and is normalised rather than ignored; `2026-02-30` is
     * not a day at all, and PHP's parser would roll it forward into March if it were
     * trusted — a range nobody asked for, on a page whose only mistake was the URL.
     */
    public function test_a_parameter_that_is_not_a_day_narrows_nothing(): void
    {
        $this->actingAsAdmin();

        Sale::factory()->count(2)->create(['sold_at' => '2026-03-10 09:00:00']);

        foreach (['sampah', '2026-02-30', ''] as $value) {
            $this->get('/sales?from='.$value)
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->has('sales.data', 2)
                    ->where('filters.from', null)
                );
        }

        // A day written by hand, in a form the date input never produces. Read as
        // that day, and reported back in the one form the rest of the application
        // writes days in.
        $this->get('/sales?from=2026-3-1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('sales.data', 2)
                ->where('filters.from', '2026-03-01')
            );
    }

    /**
     * The range composes with the other filters rather than replacing them.
     *
     * Both narrow the same query, so a range and a status together have to give the
     * intersection. A range that reset the status would look right on its own and
     * quietly widen the ledger the moment the two were used together.
     */
    public function test_the_range_narrows_the_same_query_as_the_status_filter(): void
    {
        $this->actingAsAdmin();

        $pending = Sale::factory()->create(['sold_at' => '2026-03-10 09:00:00', 'status' => 'pending']);
        Sale::factory()->create(['sold_at' => '2026-03-10 09:00:00', 'status' => 'delivered']);
        Sale::factory()->create(['sold_at' => '2026-04-10 09:00:00', 'status' => 'pending']);

        $this->get('/sales?from=2026-03-01&to=2026-03-31&status=pending')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('sales.data', 1)
                ->where('sales.data.0.id', $pending->id)
                ->where('filters.status', 'pending')
                ->where('filters.from', '2026-03-01')
                ->where('filters.to', '2026-03-31')
            );
    }

    public function test_no_range_parameters_return_the_whole_ledger(): void
    {
        $this->actingAsAdmin();

        Sale::factory()->count(3)->create();

        $this->get('/sales')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('sales.data', 3)
                ->where('filters.from', null)
                ->where('filters.to', null)
            );
    }
}
