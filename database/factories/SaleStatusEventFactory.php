<?php

namespace Database\Factories;

use App\Enums\SaleStatus;
use App\Models\Sale;
use App\Models\SaleStatusEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SaleStatusEvent>
 */
class SaleStatusEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sale_id' => Sale::factory(),
            'user_id' => null,
            'from_status' => SaleStatus::Pending,
            'to_status' => SaleStatus::Confirmed,
            'note' => fake()->optional()->sentence(),
        ];
    }
}
