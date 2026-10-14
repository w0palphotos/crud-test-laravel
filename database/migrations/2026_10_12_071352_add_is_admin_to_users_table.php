<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the administrator flag.
     *
     * A boolean rather than a roles table, because the application has exactly
     * two kinds of account: someone who works the sales desk and someone who
     * does not. A roles table, a permissions pivot and a gate would all be
     * structure for a question the answer is one column wide, and every extra
     * piece of it is a second thing to keep consistent.
     *
     * Defaulted to false so every existing row is an ordinary account the moment
     * this runs. That is the safe direction to fail: an account that has just
     * gained a column can see its own profile and nothing else, rather than
     * inheriting access to the ledger because the backfill defaulted the other
     * way.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_admin')->default(false)->after('password');
        });
    }

    /**
     * Reversible by dropping the column, which is honest here: no data is
     * transformed, and an account with no column is an ordinary account again.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_admin');
        });
    }
};
