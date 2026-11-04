<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
use App\OrderStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OrderStatusHistory>
 */
class OrderStatusHistoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'order_item_id' => null,
            'item_name' => null,
            'actor_id' => User::factory()->admin()->state(['name' => 'Example Administrator']),
            'actor_name' => 'Example Administrator',
            'event_type' => 'order_created',
            'from_status' => null,
            'to_status' => OrderStatus::Pending->value,
            'notes' => null,
            'submission_key' => (string) Str::uuid(),
        ];
    }
}
