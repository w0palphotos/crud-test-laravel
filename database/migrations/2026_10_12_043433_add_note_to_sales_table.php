<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The salesperson's own note on the sale.
     *
     * Long text because it is rich text with formatting and inline images, not a
     * sentence — a salesperson attaches the delivery instructions, the negotiated
     * terms, a photo of the goods.
     *
     * Nullable: most sales are routine, and forcing every one to carry a note
     * would only produce empty strings. The status_events.note column is a
     * different thing — an audit trail of who moved the status when — and is
     * deliberately not where user-authored content goes. Mixing the two would
     * make the audit history depend on what a user chose to type.
     *
     * Text and not string, so a note is never silently truncated at 255
     * characters by a driver that has no opinion about the content.
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->longText('note')->nullable()->after('grand_total');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropColumn('note');
        });
    }
};
