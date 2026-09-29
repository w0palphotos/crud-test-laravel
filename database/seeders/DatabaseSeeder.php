<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Products are seeded everywhere so a deployed demo has something to show.
     * The test user is not: its password is the literal string "password"
     * (UserFactory), so seeding it in production would create a
     * known-credential backdoor. POST /api/account is public and returns a
     * token, so there is nothing to seed for.
     */
    public function run(): void
    {
        Product::factory(10)->create();

        if (app()->environment('production')) {
            return;
        }

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
}
