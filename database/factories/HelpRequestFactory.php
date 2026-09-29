<?php

namespace Database\Factories;

use App\Enums\HelpRequestStatus;
use App\Enums\HelpTopic;
use App\Models\HelpRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HelpRequest>
 */
class HelpRequestFactory extends Factory
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
            'topic' => HelpTopic::SomethingBroken,
            'message' => fake()->sentence(12),
            'platform' => 'web',
            'status' => HelpRequestStatus::Open,
        ];
    }

    public function resolved(): static
    {
        return $this->state(fn (): array => [
            'status' => HelpRequestStatus::Resolved,
            'resolved_at' => now(),
            'staff_note' => 'Fixed on our side.',
        ]);
    }
}
