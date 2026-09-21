<?php

namespace Database\Factories;

use App\Enums\ClientRole;
use App\Models\ClientAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientAccount>
 */
class ClientAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'role' => ClientRole::Resident,
            'propertyware_id' => (string) fake()->unique()->numberBetween(100000, 999999),
        ];
    }

    /**
     * Indicate that the account belongs to a tenant.
     */
    public function resident(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => ClientRole::Resident,
        ]);
    }

    /**
     * Indicate that the account belongs to an owner.
     */
    public function owner(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => ClientRole::Owner,
        ]);
    }
}
