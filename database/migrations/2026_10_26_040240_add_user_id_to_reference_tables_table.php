<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['cities', 'categories', 'tags', 'salespeople'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete()
                    ->after('id');
            });
        }
    }

    public function down(): void
    {
        foreach (['cities', 'categories', 'tags', 'salespeople'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('user_id');
            });
        }
    }
};
