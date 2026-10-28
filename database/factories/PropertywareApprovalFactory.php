<?php

namespace Database\Factories;

use App\Models\PropertywareApproval;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PropertywareApproval>
 */
class PropertywareApprovalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'work_order_id' => (string) fake()->numberBetween(1000, 9999),
            'work_order_number' => (string) fake()->numberBetween(10000, 99999),
            'approved' => true,
            'approved_date' => now()->toDateString(),
            'approved_by' => fake()->name(),
            'approved_by_email' => fake()->safeEmail(),
            'approval_comment' => 'I approve this work order.',
            'recorded_at' => now(),
        ];
    }
}
