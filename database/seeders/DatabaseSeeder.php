<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * The catalogue and the ledger come from SalesDeskSeeder, which owns both: it
     * replaces them on every run, so `db:seed` can be re-run without piling a
     * second ledger on top of the first. It also seeds a real catalogue — named
     * motorcycles with suppliers and categories — rather than faker words, which
     * is more useful to look at on the products page and gives the assistant
     * something coherent to be asked about.
     *
     * The test user is not seeded in production: its password is the literal
     * string "password" (UserFactory), so seeding it there would create a
     * known-credential backdoor. POST /api/account is public and returns a
     * token, so there is nothing to seed for.
     */
    public function run(): void
    {
        $this->call(SalesDeskSeeder::class);

        if (app()->environment('production')) {
            return;
        }

        // firstOrCreate rather than create, so re-seeding an existing development
        // database finds the existing account instead of failing on its unique
        // email — which is what made `migrate --seed` unusable a second time.
        User::firstOrCreate(
            ['email' => 'test@example.com'],
            ['name' => 'Test User', 'password' => 'password'],
        );
    }
}
