<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The images filed with a sale.
 *
 * An image is shown, a document is signed — the two live on different disks
 * and different tables. The file itself is on the images disk and only the
 * path is kept here, because a url of a private object is signed and expires.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Table may already exist from a previous (partial) run; guard so the
        // migration is idempoten and does not block later migrations.
        if (! Schema::hasTable('sale_images')) {
            Schema::create('sale_images', function (Blueprint $table) {
                $table->id();

                // An image without a sale is a file nobody can find, so the sale
                // takes it with it — the same bargain the sale's own rows strike.
                $table->foreignId('sale_id')->constrained()->cascadeOnDelete();

                // Where the file is, on the images disk. The name on disk is
                // generated, because a client-supplied one is a client-supplied path.
                $table->string('path');

                // Where it sits in the row the desk arranged: the index in the
                // submitted list becomes sort_order, so the order is kept.
                $table->unsignedInteger('sort_order');

                $table->timestamps();

                $table->index(['sale_id', 'sort_order']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_images');
    }
};
