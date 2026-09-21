<?php

namespace Database\Factories;

use App\Models\ClientAccount;
use App\Models\User;
use App\Models\WorkOrderApproval;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WorkOrderApproval>
 */
class WorkOrderApprovalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reference' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'client_account_id' => ClientAccount::factory()->owner(),
            'propertyware_contact_id' => (string) fake()->numberBetween(100000, 999999),
            'work_order_id' => (string) fake()->numberBetween(1000, 9999),
            'work_order_number' => (string) fake()->numberBetween(10000, 99999),
            'decision' => 'approved',
            'approver_name' => fake()->name(),
            'decided_at' => now(),
        ];
    }
}
