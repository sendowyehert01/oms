<?php

namespace Database\Factories;

use App\Models\User;
use App\UserRole;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Example Staff',
            'email' => 'staff-'.Str::uuid().'@example.test',
            'password' => 'test-password-123',
            'role' => UserRole::Staff,
        ];
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes): array => ['role' => UserRole::Admin]);
    }

    public function productionMonitor(): static
    {
        return $this->state(fn (array $attributes): array => ['role' => UserRole::ProductionMonitor]);
    }
}
