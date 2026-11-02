<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
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
            'product_name' => 'Plastic bags',
            'quantity' => 100,
            'unit_price' => '1.25',
        ];
    }

    public function withSpecifications(): static
    {
        return $this->state(fn (array $attributes): array => [
            'material_color' => 'LDPE / transparent',
            'size' => '8 x 12 inches / 0.03 mm',
            'print_design_colors' => 'Company logo / 2 colors',
            'sealing' => 'Bottom Seal',
            'puncher' => 'None',
            'customization_notes' => 'Pack in bundles of 100.',
        ]);
    }
}
