<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extending the product catalogue with the relations the sales desk needs.
 *
 * `products` itself is only given one nullable column. It predates this feature,
 * the API contract validates it through StoreProductRequest, and requiring a
 * category or a supplier here would turn a catalogue change into a breaking API
 * change for every existing caller. Both pivots are therefore nullable-side:
 * a product may be uncategorised and may have no supplier on file.
 *
 * The two pivots are kept as separate tables because they are not the same
 * relationship. A product sits in any number of categories, which is a pure
 * many-to-many. It is also supplied by any number of suppliers, but that pairing
 * carries facts of its own — what it costs and how long the lead time is — which
 * is why the columns live on the pivot instead of on either side.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Sanitised rich text from the editor. Nullable and plain text rather
            // than a JSON document, because the rendered HTML is what the rest of
            // the app reads and a JSON blob would mean re-rendering it everywhere.
            //
            // No after(): SQLite has no column ordering to add to, and the app
            // runs on SQLite in development and in the default test suite.
            $table->longText('description_html')->nullable();
        });

        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('path');

            // Alt text is part of the image, not decoration about it. A missing
            // one is the difference between a described picture and an
            // unannounced one to anyone using a screen reader.
            $table->string('alt_text')->nullable();

            // Explicit ordering rather than relying on insertion order, which
            // SQLite and Postgres do not promise to preserve the same way.
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['product_id', 'sort_order']);
        });

        Schema::create('category_product', function (Blueprint $table) {
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            // The pair is the identity of the row. A composite primary key makes a
            // double insert impossible in a way two separate indexes do not.
            $table->primary(['category_id', 'product_id']);
        });

        Schema::create('product_supplier', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();

            // What we pay, not what the customer pays. Keeping it on the pivot is
            // the point: it is true of one product from one supplier and of
            // neither product nor supplier on their own.
            $table->decimal('unit_cost', 14, 2);

            $table->unsignedSmallInteger('lead_time_days')->default(0);

            // The supplier the desk reaches for first. A partial unique index
            // would enforce one per product, but the rule is advisory here: a
            // desk may legitimately have two on file and no primary while
            // it negotiates, and a hard constraint would block that.
            $table->boolean('is_primary')->default(false);

            $table->primary(['product_id', 'supplier_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_supplier');
        Schema::dropIfExists('category_product');
        Schema::dropIfExists('product_images');

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('description_html');
        });
    }
};
