<?php

namespace Database\Factories;

use App\Models\Announcement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Announcement>
 */
class AnnouncementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => 'Office closed Friday',
            'body' => fake()->sentence(14),
            'audience' => 'all',
            'tone' => 'info',
        ];
    }

    public function audience(string $audience): static
    {
        return $this->state(fn (): array => ['audience' => $audience]);
    }

    public function ended(): static
    {
        return $this->state(fn (): array => ['ends_at' => now()->subDay()]);
    }

    public function scheduled(): static
    {
        return $this->state(fn (): array => ['starts_at' => now()->addDay()]);
    }
}
