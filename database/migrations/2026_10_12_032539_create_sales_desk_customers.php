<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customers, and the commercial profile that belongs to one.
 *
 * The split is the one-to-one in this schema, and it exists because the two have
 * genuinely different lifecycles. A customer exists the moment someone orders
 * once. The profile — their billing address, tax number, credit limit and
 * payment terms — is written later, by the sales desk, once the relationship is
 * worth onboarding. Putting those columns on `customers` would force them all to
 * be nullable, which hides the fact that an unknown credit limit is a different
 * thing from a zero credit limit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone', 24)->nullable();
            $table->timestamps();
        });

        Schema::create('customer_profiles', function (Blueprint $table) {
            $table->id();

            // Unique, and it is the whole of the one-to-one: the database refuses
            // a second profile for the same customer even if two requests race.
            $table->foreignId('customer_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();

            $table->text('address')->nullable();

            // NPWP in Indonesia: the tax identity number shown on an invoice.
            $table->string('tax_id', 32)->nullable();

            // Rupiah. The ceiling the sales desk may let this customer owe at
            // once, which is what the order form checks a draft sale against.
            $table->decimal('credit_limit', 14, 2)->default(0);

            // Prose rather than a lookup table: "Net 30" and "COD on delivery"
            // are both agreements with a counterparty, not a closed set.
            $table->string('payment_terms', 64)->default('COD');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_profiles');
        Schema::dropIfExists('customers');
    }
};
