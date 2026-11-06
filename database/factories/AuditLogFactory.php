<?php

namespace Database\Factories;

use App\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use App\UserRole;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'actor_id' => User::factory()->state(['name' => 'Example Staff']),
            'actor_name' => 'Example Staff',
            'actor_role' => UserRole::Staff,
            'action' => AuditAction::TabSwitched,
            'subject_type' => 'tab',
            'subject_id' => null,
            'subject_name' => 'Orders',
            'order_id' => null,
            'order_number' => null,
            'statement' => 'Example Staff switches to the "Orders" tab.',
        ];
    }

    public function by(User $actor): static
    {
        return $this->state(fn (array $attributes): array => [
            'actor_id' => $actor->id,
            'actor_name' => $actor->name,
            'actor_role' => $actor->role,
            'statement' => $actor->name.' switches to the "Orders" tab.',
        ]);
    }
}
