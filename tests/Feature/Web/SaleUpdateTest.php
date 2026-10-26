<?php

namespace Tests\Feature\Web;

use App\Enums\SaleStatus;
use App\Models\City;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Salesperson;
use App\Models\SaleStatusEvent;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Amending a sale.
 *
 * The invariants this file holds are the ones the earlier decision against
 * editing was protecting: that the stored totals are the items' arithmetic and
 * never a number a browser sent, and that the note stays safe to render even
 * though nothing sanitises it at render time.
 */
class SaleUpdateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A sale with one line, priced so the arithmetic in each test is readable.
     */
    private function sale(): Sale
    {
        $sale = Sale::factory()->create([
            'status' => SaleStatus::Pending,
            'sold_at' => '2026-03-04 09:30:00',
        ]);

        SaleItem::factory()->for($sale)->create([
            'quantity' => 2,
            'unit_price' => 15000,
            'discount_total' => 1000,
            'line_total' => 29000,
        ]);

        SaleStatusEvent::factory()->for($sale)->create([
            'from_status' => null,
            'to_status' => SaleStatus::Pending,
            'note' => 'Penjualan dibuat.',
        ]);

        return $sale->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => Customer::factory()->create()->id,
            'salesperson_id' => Salesperson::factory()->create()->id,
            'city_id' => City::factory()->create()->id,
            'sold_at' => '2026-05-06T14:45',
            'discount_total' => 0,
            'tax_total' => 0,
            'note' => null,
            'tags' => [],
            'items' => [
                [
                    'product_id' => Product::factory()->create()->id,
                    'quantity' => 1,
                    'unit_price' => 10000,
                ],
            ],
        ], $overrides);
    }

    public function test_every_business_field_can_be_amended(): void
    {
        $sale = $this->sale();
        $user = User::factory()->admin()->create();
        $reference = $sale->reference;

        $tag = Tag::factory()->create();
        $payload = $this->payload([
            'customer_id' => $sale->customer_id,
            'note' => '<p>Antar sebelum jam 4</p>',
            'tags' => [$tag->id],
            'items' => [[
                'product_id' => $sale->items()->first()->product_id,
                'quantity' => 3,
                'unit_price' => 15000,
                'discount_total' => 1000,
            ]],
        ]);

        $response = $this->actingAs($user)->patch("/sales/{$sale->id}", $payload);

        $response->assertRedirect(route('sales.show', $sale));
        $response->assertSessionHas('status');

        $sale->refresh()->load(['items', 'tags']);

        $this->assertSame($reference, $sale->reference);
        $this->assertSame($payload['customer_id'], $sale->customer_id);
        $this->assertSame($payload['salesperson_id'], $sale->salesperson_id);
        $this->assertSame($payload['city_id'], $sale->city_id);
        $this->assertSame('2026-05-06 14:45:00', $sale->sold_at->format('Y-m-d H:i:s'));
        $this->assertSame('<p>Antar sebelum jam 4</p>', $sale->note);
        $this->assertSame([$tag->id], $sale->tags->pluck('id')->all());

        $this->assertCount(1, $sale->items);
        $this->assertSame(3, $sale->items->first()->quantity);
    }

    /**
     * The arithmetic is the whole point of the refusal to take a total from the
     * request, so this is the assertion that has to survive an amendment.
     */
    public function test_the_totals_are_recomputed_and_not_taken_from_the_request(): void
    {
        $sale = $this->sale();

        $this->actingAs(User::factory()->admin()->create())->patch("/sales/{$sale->id}", $this->payload([
            // A browser claiming a grand total of 1 rupiah, as at creation.
            'grand_total' => 1,
            'discount_total' => 3000,
            'tax_total' => 2000,
            'items' => [[
                'product_id' => Product::factory()->create()->id,
                'quantity' => 3,
                'unit_price' => 25000,
                'discount_total' => 5000,
            ]],
        ]));

        $sale->refresh();

        $this->assertSame(75000.0, (float) $sale->subtotal);
        $this->assertSame(8000.0, (float) $sale->discount_total);
        $this->assertSame(2000.0, (float) $sale->tax_total);
        $this->assertSame(69000.0, (float) $sale->grand_total);
    }

    public function test_the_lines_are_replaced_rather_than_merged(): void
    {
        $sale = $this->sale();
        $kept = Product::factory()->create();
        $added = Product::factory()->create();
        $discarded = $sale->items()->first()->product_id;

        $this->actingAs(User::factory()->admin()->create())->patch("/sales/{$sale->id}", $this->payload([
            'items' => [
                ['product_id' => $kept->id, 'quantity' => 1, 'unit_price' => 5000],
                ['product_id' => $added->id, 'quantity' => 2, 'unit_price' => 7000],
            ],
        ]));

        $sale->refresh();

        // The line the form did not send is gone rather than kept: the posted list
        // is the whole set, which is what lets the totals be recomputed from it.
        $this->assertSame([$kept->id, $added->id], $sale->items->pluck('product_id')->all());
        $this->assertNotContains($discarded, $sale->items->pluck('product_id')->all());
    }

    public function test_a_sale_still_needs_at_least_one_item(): void
    {
        $sale = $this->sale();

        $this->actingAs(User::factory()->admin()->create())
            ->patch("/sales/{$sale->id}", $this->payload(['items' => []]))
            ->assertSessionHasErrors('items');

        $this->assertSame(1, $sale->items()->count());
    }

    /**
     * The amendment path is a second door into a field the detail page renders
     * with v-html, and nothing sanitises it on the way out. This is the test that
     * would fail if someone added the route without the purifier.
     */
    public function test_the_note_is_sanitised_on_the_way_in(): void
    {
        $sale = $this->sale();

        $this->actingAs(User::factory()->admin()->create())->patch("/sales/{$sale->id}", $this->payload([
            'note' => '<p>Kirim <strong>besok</strong></p><script>alert(1)</script>',
        ]));

        $note = $sale->refresh()->note;

        $this->assertStringContainsString('<strong>besok</strong>', $note);
        $this->assertStringNotContainsString('<script', $note);
    }

    public function test_the_reference_and_the_status_are_not_the_editors_to_change(): void
    {
        $sale = $this->sale();
        $reference = $sale->reference;

        $this->actingAs(User::factory()->admin()->create())->patch("/sales/{$sale->id}", $this->payload([
            'reference' => 'SLV-HACKED',
            'status' => SaleStatus::Delivered->value,
        ]));

        $sale->refresh();

        $this->assertSame($reference, $sale->reference);
        $this->assertSame(SaleStatus::Pending, $sale->status);
    }

    /**
     * A sale that changed with nothing on its history reads as a sale that never
     * changed, which is the exact reading the edit is meant to prevent.
     */
    public function test_an_amendment_leaves_a_mark_on_the_history(): void
    {
        $sale = $this->sale();
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->patch("/sales/{$sale->id}", $this->payload());

        // The relation orders by id for the timeline, so the newest entry is read
        // off the end of the collection rather than off a fresh desc order.
        $event = $sale->refresh()->statusEvents->last();

        $this->assertSame('Penjualan diubah.', $event->note);
        $this->assertSame($user->id, $event->user_id);

        // The status did not move, and the event says so with both sides equal
        // rather than with a from the sale never came from.
        $this->assertSame($event->from_status, $event->to_status);
    }

    public function test_a_status_note_can_be_amended_and_cleared(): void
    {
        $sale = $this->sale();
        $event = $sale->statusEvents()->first();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->patch("/sales/{$sale->id}/status-events/{$event->id}", ['note' => 'Pelanggan minta dikirim sore'])
            ->assertRedirect(route('sales.show', $sale));

        $this->assertSame('Pelanggan minta dikirim sore', $event->refresh()->note);

        // Clearing is an intention, not an empty value the validator refuses.
        $this->actingAs($admin)
            ->patch("/sales/{$sale->id}/status-events/{$event->id}", ['note' => ''])
            ->assertRedirect(route('sales.show', $sale));

        $this->assertNull($event->refresh()->note);
    }

    /**
     * Both ids are in the path, so binding them independently would let a request
     * naming this sale and another sale's event rewrite the wrong history.
     */
    public function test_an_event_cannot_be_amended_through_another_sale(): void
    {
        $sale = $this->sale();
        $event = $sale->statusEvents()->first();
        $other = $this->sale();

        $this->actingAs(User::factory()->admin()->create())
            ->patch("/sales/{$other->id}/status-events/{$event->id}", ['note' => 'Dibaca dari penjualan lain']);

        $this->assertSame('Penjualan dibuat.', $event->refresh()->note);
    }

    public function test_the_movement_of_a_status_is_still_checked(): void
    {
        $sale = $this->sale();

        // Pending may become Confirmed, never Delivered. The amendment route
        // carries no status at all, so the only way to reach Delivered from here
        // is the status route, which refuses.
        $this->actingAs(User::factory()->admin()->create())
            ->patch("/sales/{$sale->id}/status", ['status' => SaleStatus::Delivered->value])
            ->assertSessionHasErrors('status');
    }

    public function test_an_ordinary_account_can_amend_a_sale(): void
    {
        $sale = $this->sale();

        // Sales (non-admin) may amend any sale — the gate opens and validation
        // runs instead of a 403.
        $response = $this->actingAs(User::factory()->create())
            ->patch("/sales/{$sale->id}", $this->payload());

        $this->assertTrue(
            in_array($response->status(), [200, 302], true),
            "Expected PATCH /sales/{$sale->id} to be reachable by sales, got {$response->status()} instead."
        );
    }

    public function test_a_guest_is_sent_to_the_login_form(): void
    {
        $sale = $this->sale();

        $this->patch("/sales/{$sale->id}", $this->payload())->assertRedirect(route('login'));
    }

    /**
     * The editor has to start from the record, or an amendment would quietly
     * rewrite fields nobody opened the page to change.
     */
    public function test_the_detail_page_carries_what_the_editor_starts_from(): void
    {
        $sale = $this->sale();
        $product = $sale->items()->first()->product;

        $this->actingAs(User::factory()->admin()->create())
            ->get("/sales/{$sale->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('updateUrl', route('sales.update', $sale))
                ->where('sale.customerId', $sale->customer_id)
                ->where('sale.salespersonId', $sale->salesperson_id)
                ->where('sale.cityId', $sale->city_id)
                // The raw local time, not the formatted one: an editor seeded with
                // "04 Mar 2026 09:30" would post a string the request cannot parse.
                ->where('sale.soldAtInput', '2026-03-04T09:30')
                ->where('items.0.productId', $product->id)
                ->has('statusEvents')
                ->where('statusEvents.0.id', $sale->statusEvents()->first()->id)
            );
    }

    /**
     * A file embedded in the note is an object on the images disk — whether it is
     * an image (`<img src>`) or a linked document (`<a href>`). When an amendment
     * takes it out of the note, nothing points at it any more, so the object has
     * to go too — otherwise storage grows a copy of every file nothing references.
     */
    public function test_a_file_removed_from_the_note_is_deleted_from_storage(): void
    {
        config()->set('images.disk', 'images');
        config()->set('images.directory', 'sale-notes');
        config()->set('filesystems.disks.images', [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => '/storage',
            'visibility' => 'public',
        ]);

        $sale = $this->sale();
        $imagePath = 'sale-notes/notes/abc123.jpg';
        $imageUrl = '/storage/'.$imagePath;
        $documentPath = 'sale-notes/notes/def456.pdf';
        $documentUrl = '/storage/'.$documentPath;

        Storage::disk('images')->put($imagePath, 'an image');
        Storage::disk('images')->put($documentPath, 'a document');

        $this->assertTrue(Storage::disk('images')->exists($imagePath));
        $this->assertTrue(Storage::disk('images')->exists($documentPath));

        $admin = User::factory()->admin()->create();

        $payload = $this->payload([
            'note' => "<p><img src=\"{$imageUrl}\" alt=\"bukti\"></p>"
                ."<p><a href=\"{$documentUrl}\">Surat jalan</a></p>",
        ]);

        // Both files are there while the note references them.
        $this->actingAs($admin)
            ->patch("/sales/{$sale->id}", $payload)
            ->assertRedirect(route('sales.show', $sale));

        $this->assertTrue(Storage::disk('images')->exists($imagePath));
        $this->assertTrue(Storage::disk('images')->exists($documentPath));

        // Removing both files from the note removes both objects from storage.
        $this->actingAs($admin)
            ->patch("/sales/{$sale->id}", array_merge($payload, ['note' => '<p>Dikirim esok.</p>']))
            ->assertRedirect(route('sales.show', $sale));

        $this->assertFalse(Storage::disk('images')->exists($imagePath));
        $this->assertFalse(Storage::disk('images')->exists($documentPath));
    }
}
