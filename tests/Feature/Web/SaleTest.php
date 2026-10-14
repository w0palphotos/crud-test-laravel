<?php

namespace Tests\Feature\Web;

use App\Models\City;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Salesperson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SaleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => Customer::factory()->create()->id,
            'salesperson_id' => Salesperson::factory()->create()->id,
            'city_id' => City::factory()->create()->id,
            'discount_total' => 0,
            'tax_total' => 0,
            'note' => null,
            'items' => [
                [
                    'product_id' => Product::factory()->create()->id,
                    'quantity' => 2,
                    'unit_price' => 15000,
                ],
            ],
        ], $overrides);
    }

    public function test_the_form_offers_the_choices_it_needs(): void
    {
        $customer = Customer::factory()->create(['name' => 'Toko Aman']);
        $product = Product::factory()->create(['name' => 'Kopi Gula']);

        $this->actingAs(User::factory()->admin()->create())
            ->get('/sales/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Sales/Form')
                ->has('customers', 1)
                ->has('salespeople')
                ->has('cities')
                ->has('products')
                ->where('customers.0.name', 'Toko Aman')
                ->where('products.0.name', 'Kopi Gula')
            );
    }

    public function test_a_sale_can_be_created(): void
    {
        $user = User::factory()->admin()->create();

        $response = $this->actingAs($user)->post('/sales', $this->payload());

        $sale = Sale::sole();

        $response->assertRedirect(route('sales.show', $sale));
        $response->assertSessionHas('success');

        $this->assertSame(30000.0, (float) $sale->grand_total);
        $this->assertCount(1, $sale->items);
    }

    public function test_the_totals_are_recomputed_and_not_taken_from_the_request(): void
    {
        $this->actingAs(User::factory()->admin()->create())->post('/sales', $this->payload([
            // A browser claiming a grand total of 1 rupiah.
            'grand_total' => 1,
            'items' => [[
                'product_id' => Product::factory()->create()->id,
                'quantity' => 3,
                'unit_price' => 25000,
                'discount_total' => 5000,
            ]],
        ]));

        $sale = Sale::sole();

        $this->assertSame(75000.0, (float) $sale->subtotal);
        $this->assertSame(5000.0, (float) $sale->discount_total);
        $this->assertSame(70000.0, (float) $sale->grand_total);
    }

    public function test_the_note_is_sanitised_on_the_way_in(): void
    {
        $this->actingAs(User::factory()->admin()->create())->post('/sales', $this->payload([
            'note' => '<p>Deliver <strong>carefully</strong></p>'
                .'<script>alert(1)</script>'
                .'<img src="x" onerror="alert(2)">',
        ]));

        $note = Sale::sole()->note;

        $this->assertStringContainsString('<strong>carefully</strong>', $note);
        $this->assertStringNotContainsString('<script', $note);
        $this->assertStringNotContainsString('onerror', $note);
    }

    public function test_an_uploaded_image_survives_from_upload_to_sale_detail(): void
    {
        config()->set('images.disk', 'images');
        Storage::fake('images');

        $user = User::factory()->admin()->create();
        $this->actingAs($user);

        $upload = $this->post('/admin/uploads', [
            'image' => $this->png(),
        ])->assertOk();

        $url = $upload->json('url');
        $path = $upload->json('path');

        Storage::disk('images')->assertExists($path);

        $this->post('/sales', $this->payload([
            'note' => '<p>Nota</p><img src="'.$url.'" alt="Nota">',
        ]))->assertRedirect();

        $sale = Sale::sole();

        $this->assertStringContainsString('src="'.$url.'"', $sale->note);

        $this->get("/sales/{$sale->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('sale.note', $sale->note)
            );
    }

    /**
     * A genuine PNG, as bytes.
     *
     * Copied in miniature rather than using UploadedFile::fake()->image()
     * because that factory needs GD, which the runtime image deliberately does
     * not install. The sniffer only needs the signature.
     */
    private function png(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'sale-image-test');
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));

        return new UploadedFile($path, 'nota.png', 'image/png', null, true);
    }

    public function test_a_sale_can_be_read(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->post('/sales', $this->payload([
            'note' => '<p>Leave at <em>gudang 2</em></p>',
        ]));

        $sale = Sale::sole();

        $this->actingAs($user)
            ->get("/sales/{$sale->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Sales/Show')
                ->where('sale.reference', $sale->reference)
                ->where('sale.note', $sale->note)
                ->has('items', 1)
                // Every sale has at least one history entry, or the status
                // column and the audit trail could disagree with no record of
                // how they came to.
                ->has('statusEvents', 1)
            );
    }

    public function test_guests_cannot_reach_the_sale_form(): void
    {
        $this->get('/sales/create')->assertRedirect(route('login'));
        $this->post('/sales', $this->payload())->assertRedirect(route('login'));
    }

    public function test_a_sale_needs_at_least_one_item(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post('/sales', $this->payload(['items' => []]))
            ->assertSessionHasErrors('items');

        $this->assertSame(0, Sale::count());
    }

    public function test_a_line_names_the_line_that_is_wrong(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post('/sales', $this->payload([
                'items' => [
                    ['product_id' => Product::factory()->create()->id, 'quantity' => 1, 'unit_price' => 1000],
                    ['product_id' => Product::factory()->create()->id, 'quantity' => 0, 'unit_price' => 1000],
                ],
            ]))
            ->assertSessionHasErrors('items.1.quantity');
    }

    public function test_a_line_cannot_price_itself_with_an_unknown_product(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post('/sales', $this->payload([
                'items' => [[
                    'product_id' => Product::factory()->create()->id + 9999,
                    'quantity' => 1,
                    'unit_price' => 1000,
                ]],
            ]))
            ->assertSessionHasErrors('items.0.product_id');
    }
}
