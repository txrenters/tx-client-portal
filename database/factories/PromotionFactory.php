<?php

namespace Database\Factories;

use App\Models\Promotion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Promotion>
 */
class PromotionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => 'Refer a friend',
            'body' => fake()->sentence(),
            'button_label' => 'Refer an owner',
            'button_link' => Promotion::LINK_REFERRALS,
            'audience' => 'all',
            'is_active' => true,
            'priority' => 0,
            'stop_after_referral' => false,
        ];
    }
}
