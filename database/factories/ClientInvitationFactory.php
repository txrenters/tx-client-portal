<?php

namespace Database\Factories;

use App\Enums\ClientRole;
use App\Models\ClientInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ClientInvitation>
 */
class ClientInvitationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'name' => fake()->name(),
            'role' => ClientRole::Resident,
            'propertyware_id' => (string) fake()->unique()->numberBetween(100000, 999999),
            'token_hash' => ClientInvitation::hashToken(Str::random(64)),
            'expires_at' => now()->addDays(7),
        ];
    }

    /**
     * Use the given plain token for the invitation.
     */
    public function withToken(string $token): static
    {
        return $this->state(fn (array $attributes) => [
            'token_hash' => ClientInvitation::hashToken($token),
        ]);
    }

    /**
     * Indicate that the invitation is for an owner.
     */
    public function owner(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => ClientRole::Owner,
        ]);
    }

    /**
     * Indicate that the invitation has expired.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subMinute(),
        ]);
    }

    /**
     * Indicate that the invitation was already accepted.
     */
    public function accepted(): static
    {
        return $this->state(fn (array $attributes) => [
            'accepted_at' => now(),
        ]);
    }
}
