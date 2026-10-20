<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The documents filed with a sale.
 *
 * A sale's note is markup, and markup is rendered inside the page. A document is
 * a file: it has a name of its own, a size, and a type, and none of that belongs
 * in a column of sanitised HTML. The two are kept apart for the same reason the
 * gallery is kept out of the note — one is text the page shows, the other is a thing
 * the page offers.
 *
 * The file itself lives on the documents disk, in a private bucket, and the path
 * here is what finds it again. A path and not a URL, because the URL of a private
 * object is signed and expires; storing one would make the link die on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_documents', function (Blueprint $table) {
            $table->id();

            // A document without a sale is a file nobody can find, so the sale
            // takes it with it. That is the same bargain the sale's own rows
            // strike: they exist as part of a sale, not beside one.
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();

            // Where the file is, on the documents disk. The name on disk is
            // generated, because a client-supplied one is a client-supplied
            // path.
            $table->string('path');

            // The name the person gave it, which is the only name they will
            // recognise. "surat-jalan-042.pdf" is a document; "9f2c1a….pdf" is
            // a file nobody filed.
            $table->string('original_name');

            // What it is and how big, so the page can say both without asking
            // storage for them on every visit.
            $table->string('mime_type');
            $table->unsignedBigInteger('size');

            // Who put it there. Null when that person has left, for the same
            // reason the status history keeps its actor: the document stays, and
            // so does the fact that somebody put it there.
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Newest first is the usual reading order for a set of attachments.
            $table->index(['sale_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_documents');
    }
};
