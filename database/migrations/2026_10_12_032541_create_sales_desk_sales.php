<?php

use App\Enums\SaleStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The fact table and everything hanging off it.
 *
 * `sales` is the header: who bought, from whom, in which city, when, and what it
 * came to. The money is stored as four columns rather than derived from the items
 * on every read, because a sale's totals are what the dashboard groups and sorts
 * on, and recomputing a sum per row to render a table is the difference between
 * an index scan and a full one.
 *
 * The items keep their own price snapshot rather than pointing at the catalogue's
 * current price. A price change must not silently rewrite what a customer was
 * quoted last March.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();

            // Human-facing, quoted in the UI and on the invoice. Unique so a
            // reference can be looked up without a scan.
            $table->string('reference')->unique();

            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('salesperson_id')->constrained()->restrictOnDelete();
            $table->foreignId('city_id')->constrained()->restrictOnDelete();

            // The enum's backing value. An index because the dashboard's default
            // view is "everything not yet settled", and that is a range scan on
            // this column.
            $table->enum('status', SaleStatus::cases())->default(SaleStatus::Pending->value);
            $table->index('status');

            $table->timestamp('sold_at');

            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('grand_total', 14, 2)->default(0);

            $table->timestamps();

            $table->index(['sold_at', 'status']);
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            $table->unsignedInteger('quantity');

            // Snapshotted at the time of sale, not read through the product.
            $table->decimal('unit_price', 14, 2);

            // Money off this line only, so grand_total can be reconciled against
            // the lines the customer actually agreed.
            $table->decimal('discount_total', 14, 2)->default(0);

            // (quantity * unit_price) - discount_total, stored for the same reason
            // as the header totals.
            $table->decimal('line_total', 14, 2);

            $table->timestamps();
        });

        Schema::create('sale_status_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();

            // The actor. Null on delete rather than cascade: a sale's history is
            // the audit trail, and losing it because the person left would leave
            // a sale with no explanation of how it reached its current state.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->enum('from_status', SaleStatus::cases())->nullable();
            $table->enum('to_status', SaleStatus::cases());

            $table->text('note')->nullable();

            // No updated_at: an event is written once and never edited.
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();

            // Unique: one settlement per sale. A second payment against the same
            // sale is a second sale against a credit note, which is a different
            // thing and gets its own row.
            $table->foreignId('sale_id')->unique()->constrained()->cascadeOnDelete();

            $table->enum('method', ['transfer', 'cash', 'credit_card', 'cod'])->default('transfer');
            $table->enum('status', ['unpaid', 'partial', 'paid', 'refunded'])->default('unpaid');
            $table->decimal('amount', 14, 2);

            $table->timestamp('paid_at')->nullable();
            $table->string('reference')->nullable();

            $table->timestamps();
        });

        Schema::create('sale_tag', function (Blueprint $table) {
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();

            // Why this tag is on this sale. Belongs on the pairing, not the tag:
            // "priority" means something different on a rush order than on a
            // cancelled one.
            $table->string('note')->nullable();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Both timestamps, because Laravel's attach() decides a pivot is
            // "timed" if it declares created_at or updated_at in withPivot(),
            // and then writes both. A created_at-only pivot therefore fails on
            // insert with a missing updated_at column.
            $table->timestamps();

            $table->primary(['sale_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_tag');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('sale_status_events');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
    }
};
