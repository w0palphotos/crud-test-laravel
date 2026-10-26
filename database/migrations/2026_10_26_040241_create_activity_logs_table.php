<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email'); // Denormalised so a search works after the user is gone.
            $table->string('action'); // create, update, delete, login, logout
            $table->string('subject_type'); // e.g. App\Models\Product
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_name')->nullable(); // Human-readable label of the row.
            $table->json('properties')->nullable(); // Old / new values, context, etc.
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['action', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
