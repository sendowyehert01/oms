<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'order_date' => now()->toDateString(),
            'due_date' => now()->addWeek()->toDateString(),
            'status' => 'Pending',
            'submission_key' => (string) Str::uuid(),
        ];
    }
}
