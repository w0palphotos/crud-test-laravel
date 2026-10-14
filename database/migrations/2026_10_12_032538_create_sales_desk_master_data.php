<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The dimension tables the sales desk groups by.
 *
 * These are separate tables rather than free-text columns on `sales` for the
 * reason the reporting app already documents: one spelling of "Jakarta" is one
 * row, so grouping stays clean, duplicates stay impossible, and adding a
 * dimension later means a table and a foreign key rather than a rewrite of every
 * report that ever grouped on it.
 *
 * Migrations here are grouped by domain instead of one per table. The tables are
 * only meaningful together, and dropping them in one step keeps a rollback from
 * leaving a sales table pointing at a dimension that has gone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();

            // Nullable because a city may be added from imported reporting data
            // before anyone has worked out which province it sits in, and a
            // missing province is not a reason to reject a real sale.
            $table->string('province')->nullable();

            $table->timestamps();
        });

        Schema::create('salespeople', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('email')->nullable()->unique();
            $table->string('phone', 24)->nullable();

            // A percentage rather than a money column, so a pay rise does not
            // change the shape of every historical commission.
            $table->decimal('commission_rate', 5, 2)->default(0);

            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();

            $table->timestamps();
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('contact_email')->nullable();
            $table->string('phone', 24)->nullable();

            $table->timestamps();
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();

            // A hex value rather than a name, so the dashboard can use it as a
            // background without a lookup it would otherwise need to fail.
            $table->string('colour', 7)->default('#e6e8eb');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tags');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('salespeople');
        Schema::dropIfExists('cities');
    }
};
