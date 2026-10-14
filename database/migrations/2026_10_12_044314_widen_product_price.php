<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Widen products.price from decimal(10,2) to decimal(14,2).
     *
     * decimal(10,2) tops out at 99,999,999.99, and a seeded Honda Brio is priced
     * at 245,000,000 — which SQLite accepted without complaint and Postgres
     * rejects outright with a numeric field overflow. Since production is
     * Postgres, the sale-desk seeder would have failed on deploy, and the default
     * test database would never have shown it.
     *
     * Widening rather than lowering the price: a motorbike showroom is not a
     * coffee stall, and 10^8 rupiah is not a ceiling this catalogue can live
     * under. Every other money column in the sales desk — subtotal, discount,
     * tax, grand total, unit price, unit cost — is already decimal(14,2), so this
     * also removes the last place where a price column disagreed with the rest of
     * the money in the system.
     *
     * Widening a numeric column is safe: it never truncates, so no stored value
     * can be lost by it. Shrinking one could.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->decimal('price', 14, 2)->change();
        });
    }

    public function down(): void
    {
        // Intentionally left empty. up() widened price from decimal(10,2) to
        // decimal(14,2), and a rollback cannot narrow it back: rows already hold
        // values above decimal(10,2)'s ceiling (a seeded Honda Brio is priced at
        // 245,000,000 against a 99,999,999.99 maximum), so narrowing either fails
        // with a numeric overflow or truncates money. The wider column is a strict
        // superset of the old one, so leaving it is lossless in every case where
        // narrowing is lossy in the only case that matters.
    }
};
