<?php

namespace Database\Seeders;

use App\Actions\CreateSale;
use App\Enums\SaleStatus;
use App\Models\Category;
use App\Models\City;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Salesperson;
use App\Models\Supplier;
use App\Models\Tag;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A distributor's books: three sales staff, three cities, a catalogue, and a
 * sales history.
 *
 * The first seven sales are the reporting project's canonical fixture, copied
 * verbatim. Their totals are fixed rather than generated, which is the point:
 * SalesDeskSeedTest asserts the dashboard reports 615,000,000 across them, so a
 * change to the schema, the aggregation or the decimal handling shows up as a
 * failed test rather than as a plausible-looking chart.
 *
 * Everything after those seven is generated, so the month-over-month bars and
 * the pagination have something real to page through.
 */
class SalesDeskSeeder extends Seeder
{
    /**
     * The reporting project's fixture, unchanged.
     *
     * Each entry is [salesperson, city, product, amount]. The amounts are the
     * totals the dashboard must reproduce, so they are written as whole rupiah
     * rather than derived from anything that could drift.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: int}>
     */
    private const CANONICAL = [
        ['Andi', 'Jakarta', 'Honda', 120_000_000],
        ['Andi', 'Bandung', 'Yamaha', 90_000_000],
        ['Budi', 'Jakarta', 'Suzuki', 80_000_000],
        ['Budi', 'Surabaya', 'Honda', 95_000_000],
        ['Citra', 'Bandung', 'Suzuki', 70_000_000],
        ['Citra', 'Surabaya', 'Yamaha', 85_000_000],
        ['Andi', 'Jakarta', 'Yamaha', 75_000_000],
    ];

    /**
     * The canonical rows are one sale each, so their total is exactly the
     * sum of the amounts above.
     */
    public const CANONICAL_TOTAL = 615_000_000;

    /**
     * A flat wholesale unit price, so a canonical amount is reproduced exactly by
     * multiplying it by a whole quantity rather than by rounding a division.
     *
     * A distributor quotes a standard unit and sells in batches; the amounts in
     * the fixture are all whole multiples of it.
     */
    private const WHOLESALE_UNIT_PRICE = 5_000_000;

    /**
     * How many extra sales to generate behind the canonical seven.
     *
     * Fourteen months' worth, so the twelve-month revenue chart is full and the
     * earliest bars are not all zero.
     */
    private const GENERATED_SALES = 60;

    public function run(): void
    {
        // One transaction for the whole seed.
        //
        // SQLite commits to disk on every statement outside a transaction, so the
        // ~300 inserts this seeder makes cost ~300 fsyncs — measured at 60ms each
        // on this machine, which is 23 seconds of the developer waiting. Inside a
        // transaction none of that reaches the disk until the single commit.
        //
        // It is also the correct shape regardless of speed: a seed either lands
        // whole or not at all, and a half-seeded ledger is worse than an empty
        // one because it looks like data.
        DB::transaction(function (): void {
            $this->clear();

            $cities = $this->cities();
            $salespeople = $this->salespeople();
            $categories = $this->categories();
            $suppliers = $this->suppliers();
            $tags = $this->tags();

            $customers = $this->customers();
            $products = $this->products($categories, $suppliers);

            $this->canonicalSales($customers, $salespeople, $cities, $products);
            $this->generatedSales($customers, $salespeople, $cities, $products, $tags);
        });
    }

    /**
     * Empty every table this seeder writes, children before parents.
     *
     * This seeder owns the demo catalogue and the ledger outright, so re-running
     * `db:seed` replaces them rather than piling a second ledger on top. Without
     * it the second run fails on the canonical sales' fixed references — they are
     * deliberately stable so the dashboard can be pinned to them — and the
     * generated volume would double, leaving a demo whose monthly chart no longer
     * matches anything asserted.
     *
     * delete() through the query builder rather than truncate(): TRUNCATE resets
     * auto-increment counters, which would make ids depend on how many times
     * somebody seeded, and the canonical rows are looked up by reference, not id.
     */
    private function clear(): void
    {
        foreach ([
            'payments',
            'sale_items',
            'sale_status_events',
            'sale_tag',
            'sales',
            'product_images',
            'product_supplier',
            'category_product',
            'customer_profiles',
            'customers',
            'products',
            'cities',
            'salespeople',
            'categories',
            'suppliers',
            'tags',
        ] as $table) {
            DB::table($table)->delete();
        }
    }

    /**
     * @return Collection<string, City>
     */
    private function cities(): Collection
    {
        return collect([
            ['Jakarta', 'DKI Jakarta'],
            ['Bandung', 'Jawa Barat'],
            ['Surabaya', 'Jawa Timur'],
            ['Medan', 'Sumatera Utara'],
            ['Makassar', 'Sulawesi Selatan'],
        ])->mapWithKeys(fn (array $row): array => [$row[0] => City::create([
            'name' => $row[0],
            'province' => $row[1],
        ])]);
    }

    /**
     * @return Collection<string, Salesperson>
     */
    private function salespeople(): Collection
    {
        return collect([
            ['Andi', 'andi@contoh.co.id', 3.50],
            ['Budi', 'budi@contoh.co.id', 3.00],
            ['Citra', 'citra@contoh.co.id', 3.25],
        ])->mapWithKeys(fn (array $row): array => [$row[0] => Salesperson::create([
            'name' => $row[0],
            'email' => $row[1],
            'commission_rate' => $row[2],
        ])]);
    }

    /**
     * @return Collection<string, Category>
     */
    private function categories(): Collection
    {
        return collect(['Motor', 'Sparepart', 'Aksesoris'])->mapWithKeys(
            fn (string $name): array => [$name => Category::create([
                'name' => $name,
                'slug' => Str::slug($name),
            ])]
        );
    }

    /**
     * @return Collection<string, Supplier>
     */
    private function suppliers(): Collection
    {
        return collect(['PT Sinar Jaya', 'PT Nusantara Motor', 'CV Karya Abadi'])->mapWithKeys(
            fn (string $name): array => [$name => Supplier::create([
                'name' => $name,
                'contact_email' => Str::slug($name, '.').'@supplier.co.id',
            ])]
        );
    }

    /**
     * @return Collection<string, Tag>
     */
    private function tags(): Collection
    {
        return collect([
            ['priority', '#dbeafe'],
            ['credit', '#fef3c7'],
            ['repeat-customer', '#d9f2d9'],
        ])->mapWithKeys(fn (array $row): array => [$row[0] => Tag::create([
            'name' => $row[0],
            'colour' => $row[1],
        ])]);
    }

    /**
     * @return Collection<int, Customer>
     */
    private function customers(): Collection
    {
        return collect([
            ['CV Motor Sejahtera', 'Jakarta', 750_000_000],
            ['UD Berkah Jaya', 'Bandung', 400_000_000],
            ['PT Trans Logistik', 'Surabaya', 600_000_000],
            ['Bengkel Brothers', 'Jakarta', 150_000_000],
            ['Toko Spare Part 88', 'Surabaya', 90_000_000],
            ['CV Sumber Rejeki', 'Bandung', 250_000_000],
        ])->map(function (array $row): Customer {
            $customer = Customer::create([
                'name' => $row[0],
                'email' => Str::slug($row[0], '.').'@customer.co.id',
            ]);

            // Written separately rather than in the same statement, because the
            // one-to-one is the point: the customer exists first and is onboarded
            // second, exactly as the sales desk does it.
            $customer->profile()->create([
                'city_id' => City::where('name', $row[1])->value('id'),
                'address' => 'Jl.raya No. '.fake()->numberBetween(1, 200),
                'tax_id' => fake()->numerify('09.###.###.#-###.###'),
                'credit_limit' => $row[2],
                'payment_terms' => fake()->randomElement(['COD', 'Net 14', 'Net 30']),
            ]);

            return $customer;
        });
    }

    /**
     * @param  Collection<string, Category>  $categories
     * @param  Collection<string, Supplier>  $suppliers
     * @return Collection<string, Product>
     */
    private function products(
        Collection $categories,
        Collection $suppliers,
    ): Collection {
        $catalogue = [
            ['Honda', 'Vario 160', 'Motor', 27_000_000],
            ['Honda', 'Brio Satrya', 'Motor', 245_000_000],
            ['Yamaha', 'Nmax 155', 'Motor', 33_000_000],
            ['Yamaha', 'Mio Sporty', 'Motor', 21_000_000],
            ['Suzuki', 'Address 120', 'Motor', 25_000_000],
            ['Suzuki', 'Shogun 125', 'Motor', 23_000_000],
            ['Honda', 'Kampas Rem Depan', 'Sparepart', 1_250_000],
            ['Yamaha', 'Baterai 12V', 'Sparepart', 900_000],
            ['Suzuki', 'Oli Mesin 10W-40', 'Sparepart', 175_000],
            ['Honda', 'Sarung Tangan', 'Aksesoris', 150_000],
            ['Yamaha', 'Top Box 25L', 'Aksesoris', 1_800_000],
        ];

        $products = [];

        foreach ($catalogue as [$brand, $model, $category, $price]) {
            $product = Product::create([
                'name' => "{$brand} {$model}",
                'description' => "{$brand} {$model}, unitnotag standar.",
                'price' => $price,
                'stock' => fake()->numberBetween(0, 120),
            ]);

            // The plain many-to-many: the pairing is the whole of the relationship.
            $product->categories()->attach($categories[$category]);

            // The many-to-many that carries data. unit_cost is below the retail
            // price because that is what a supplier's price is.
            $product->suppliers()->attach($suppliers->random(), [
                'unit_cost' => number_format($price * 0.82, 2, '.', ''),
                'lead_time_days' => fake()->numberBetween(7, 45),
                'is_primary' => true,
            ]);

            $products["{$brand} {$model}"] = $product;
        }

        // Honda, Yamaha and Suzuki as bare products too, so the canonical fixture
        // has something to point at. The reporting project's `products` table
        // held exactly these three names.
        foreach (['Honda', 'Yamaha', 'Suzuki'] as $brand) {
            $products[$brand] ??= Product::create([
                'name' => $brand,
                'description' => "Merek {$brand}.",
                'price' => self::WHOLESALE_UNIT_PRICE,
                'stock' => 0,
            ]);
        }

        return collect($products);
    }

    /**
     * The seven canonical sales, at their recorded totals.
     *
     * They are inserted directly rather than through CreateSale so the totals
     * are stated rather than recomputed. CreateSale is exercised by its own test;
     * a fixture whose numbers depend on the code under test cannot catch that code
     * being wrong.
     *
     * @param  Collection<int, Customer>  $customers
     * @param  Collection<string, Salesperson>  $salespeople
     * @param  Collection<string, City>  $cities
     * @param  Collection<string, Product>  $products
     */
    private function canonicalSales(
        Collection $customers,
        Collection $salespeople,
        Collection $cities,
        Collection $products,
    ): void {
        foreach (self::CANONICAL as $index => [$salesperson, $city, $product, $amount]) {
            $amount = (float) $amount;

            $sale = Sale::create([
                'reference' => 'SLV-CANONICAL-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                'customer_id' => $customers->get($index % $customers->count())->id,
                'salesperson_id' => $salespeople[$salesperson]->id,
                'city_id' => $cities[$city]->id,
                'status' => SaleStatus::Delivered,
                'sold_at' => now()->subMonths(11)->startOfMonth()->addDays($index * 4),

                // Tax is deliberately zero. The fixture is the gross the
                // reporting project recorded; adding Indonesian VAT would change
                // the total and the number the dashboard is pinned to.
                'subtotal' => $amount,
                'discount_total' => 0,
                'tax_total' => 0,
                'grand_total' => $amount,
            ]);

            $sale->items()->create([
                'product_id' => $products[$product]->id,
                'quantity' => (int) ($amount / self::WHOLESALE_UNIT_PRICE),
                'unit_price' => self::WHOLESALE_UNIT_PRICE,
                'discount_total' => 0,
                'line_total' => $amount,
            ]);

            $sale->statusEvents()->create([
                'from_status' => null,
                'to_status' => SaleStatus::Delivered,
                'note' => 'Diimpor dari fixture pelaporan.',
            ]);
        }
    }

    /**
     * Background volume, spread across fourteen months.
     *
     * Written directly for the same reason as the canonical rows, and because
     * CreateSale would roll back on its own failure and leave the seed looking
     * successful.
     *
     * @param  Collection<int, Customer>  $customers
     * @param  Collection<string, Salesperson>  $salespeople
     * @param  Collection<string, City>  $cities
     * @param  Collection<string, Product>  $products
     * @param  Collection<string, Tag>  $tags
     */
    private function generatedSales(
        Collection $customers,
        Collection $salespeople,
        Collection $cities,
        Collection $products,
        Collection $tags,
    ): void {
        // The three bare brand products are not for sale; the catalogue entries
        // are. Filtering them keeps the generated rows off stock of zero.
        $sellable = $products->filter(fn (Product $product, string $name): bool => $product->stock > 0)->values();

        for ($index = 0; $index < self::GENERATED_SALES; $index++) {
            $lines = fake()->numberBetween(1, 3);
            $subtotal = 0;

            $sale = Sale::create([
                'reference' => 'SLV-'.str_pad((string) ($index + 1), 5, '0', STR_PAD_LEFT),
                'customer_id' => $customers->random()->id,
                'salesperson_id' => $salespeople->random()->id,
                'city_id' => $cities->random()->id,

                // Weighted towards settled, so the dashboard has a realistic mix
                // rather than an equal spread across six states.
                'status' => fake()->randomElement([
                    SaleStatus::Pending,
                    SaleStatus::Pending,
                    SaleStatus::Confirmed,
                    SaleStatus::Shipped,
                    SaleStatus::Delivered,
                    SaleStatus::Delivered,
                    SaleStatus::Delivered,
                    SaleStatus::Cancelled,
                ]),
                'sold_at' => now()->subMonths(fake()->numberBetween(0, 13))->subDays(fake()->numberBetween(0, 27)),

                'subtotal' => 0,
                'discount_total' => 0,
                'tax_total' => 0,
                'grand_total' => 0,
            ]);

            for ($line = 0; $line < $lines; $line++) {
                $product = $sellable->random();
                $quantity = fake()->numberBetween(1, 30);
                $lineTotal = $quantity * $product->price;

                $sale->items()->create([
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'unit_price' => $product->price,
                    'discount_total' => 0,
                    'line_total' => $lineTotal,
                ]);

                $subtotal += $lineTotal;
            }

            // Set after the lines exist, so the header total is their sum rather
            // than a second, independent guess at it.
            $sale->update([
                'subtotal' => $subtotal,
                'grand_total' => $subtotal,
            ]);

            $sale->statusEvents()->create([
                'from_status' => null,
                'to_status' => $sale->status,
                'note' => 'Penjualan dibuat.',
            ]);

            $tag = $tags->random();
            $sale->tags()->attach($tag, ['note' => 'Disemai untuk demonstrasi.']);

            if ($sale->status === SaleStatus::Delivered) {
                $sale->payment()->create([
                    'method' => fake()->randomElement(['transfer', 'cash', 'credit_card', 'cod']),
                    'status' => 'paid',
                    'amount' => $subtotal,
                    'paid_at' => $sale->sold_at,
                    'reference' => 'TRX-'.str_pad((string) ($index + 1), 6, '0', STR_PAD_LEFT),
                ]);
            }
        }
    }
}
