<?php

namespace Tests\Feature\Web;

use App\Enums\SaleStatus;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SaleStatusTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: SaleStatus}>
     */
    public static function everyStoredStatus(): array
    {
        return [
            'draf' => [SaleStatus::Draft],
            'menunggu konfirmasi' => [SaleStatus::Pending],
            'dikonfirmasi' => [SaleStatus::Confirmed],
            'dikirim' => [SaleStatus::Shipped],
            'diterima' => [SaleStatus::Delivered],
            'dibatalkan' => [SaleStatus::Cancelled],
        ];
    }

    #[DataProvider('everyStoredStatus')]
    public function test_every_status_filter_matches_only_that_status(SaleStatus $status): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $matching = Sale::factory()->create(['status' => $status]);
        Sale::factory()->create(['status' => $status === SaleStatus::Draft ? SaleStatus::Pending : SaleStatus::Draft]);

        $this->get("/sales?status={$status->value}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Sales/Index')
                ->has('sales.data', 1)
                ->where('sales.data.0.id', $matching->id)
                ->where('filters.status', $status->value)
            );
    }

    public function test_an_unknown_status_filter_is_ignored_rather_than_empty(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Sale::factory()->count(2)->create();

        $this->get('/sales?status=bukan-status')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('sales.data', 2)
                ->where('filters.status', null)
            );
    }

    public function test_a_guest_is_redirected_and_a_sales_account_may_move_a_sale(): void
    {
        $sale = Sale::factory()->create(['status' => SaleStatus::Pending]);

        $this->patch("/sales/{$sale->id}/status", ['status' => 'confirmed'])
            ->assertRedirect(route('login'));

        $this->assertSame(SaleStatus::Pending, $sale->fresh()->status);

        // The lifecycle is open to every signed-in account: the panel on the
        // sale's page is drawn for sales too, so its request must land rather
        // than answer 403. The transition itself is still what decides.
        $this->actingAs(User::factory()->create())
            ->patch("/sales/{$sale->id}/status", ['status' => 'confirmed'])
            ->assertRedirect(route('sales.show', $sale));

        $this->assertSame(SaleStatus::Confirmed, $sale->fresh()->status);
    }

    public function test_an_allowed_transition_moves_the_sale_and_writes_history(): void
    {
        $user = User::factory()->admin()->create();
        $sale = Sale::factory()->create(['status' => SaleStatus::Pending]);
        $events = $sale->statusEvents()->count();

        $this->actingAs($user)
            ->patch("/sales/{$sale->id}/status", [
                'status' => 'confirmed',
                'note' => 'Pelanggan menyetujui penawaran.',
            ])
            ->assertRedirect(route('sales.show', $sale));

        $sale->refresh();

        $this->assertSame(SaleStatus::Confirmed, $sale->status);
        $this->assertSame($events + 1, $sale->statusEvents()->count());
        $this->assertDatabaseHas('sale_status_events', [
            'sale_id' => $sale->id,
            'from_status' => SaleStatus::Pending->value,
            'to_status' => SaleStatus::Confirmed->value,
            'user_id' => $user->id,
            'note' => 'Pelanggan menyetujui penawaran.',
        ]);
    }

    public function test_a_skipped_transition_is_rejected(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $sale = Sale::factory()->create(['status' => SaleStatus::Pending]);
        $events = $sale->statusEvents()->count();

        $this->patch("/sales/{$sale->id}/status", ['status' => 'delivered'])
            ->assertSessionHasErrors('status');

        $this->assertSame(SaleStatus::Pending, $sale->fresh()->status);
        $this->assertSame($events, $sale->statusEvents()->count());
    }

    public function test_a_final_status_cannot_move_again(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $sale = Sale::factory()->create(['status' => SaleStatus::Delivered]);
        $events = $sale->statusEvents()->count();

        $this->patch("/sales/{$sale->id}/status", ['status' => 'cancelled'])
            ->assertSessionHasErrors('status');

        $this->assertSame(SaleStatus::Delivered, $sale->fresh()->status);
        $this->assertSame($events, $sale->statusEvents()->count());
    }

    public function test_a_status_move_leaves_items_and_totals_alone(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $sale = Sale::factory()->create([
            'status' => SaleStatus::Pending,
            'grand_total' => 70000,
        ]);
        $items = $sale->items()->pluck('line_total', 'id')->all();

        $this->patch("/sales/{$sale->id}/status", ['status' => 'confirmed'])
            ->assertRedirect(route('sales.show', $sale));

        $sale->refresh();

        $this->assertSame(70000.0, (float) $sale->grand_total);
        $this->assertSame($items, $sale->items()->pluck('line_total', 'id')->all());
    }

    public function test_the_detail_page_only_offers_legal_next_statuses(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $sale = Sale::factory()->create(['status' => SaleStatus::Pending]);

        $this->get("/sales/{$sale->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('nextStatuses', [
                    ['value' => 'confirmed', 'label' => 'Dikonfirmasi'],
                    ['value' => 'cancelled', 'label' => 'Dibatalkan'],
                ])
                ->has('statusUpdateUrl')
            );
    }
}
