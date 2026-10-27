<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_error_logs', function (Blueprint $table) {
            $table->id();
            $table->string('channel')->default('coding-errors')->index();
            $table->string('level')->default('ERROR')->index();
            $table->text('message');
            $table->text('stack')->nullable();
            $table->json('context')->nullable();
            $table->string('environment')->default('production');
            $table->timestamps();

            $table->index(['channel', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_error_logs');
    }
};
