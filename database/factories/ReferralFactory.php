<?php

namespace Database\Factories;

use App\Enums\ClientRole;
use App\Enums\ReferralStatus;
use App\Models\ClientAccount;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Referral>
 */
class ReferralFactory extends Factory
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
            'client_account_id' => fn (array $attributes): int => ClientAccount::factory()->owner()->create(['user_id' => $attributes['user_id']])->id,
            'referrer_name' => fake()->name(),
            'referrer_role' => ClientRole::Owner->value,
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->safeEmail(),
            'phone' => '(281) 555-0123',
            'property_address' => fake()->streetAddress().', Houston, TX 77007',
            'property_details' => null,
            'source' => 'Referral from current customer',
            'status' => ReferralStatus::New,
        ];
    }

    public function status(ReferralStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status, 'status_changed_at' => now()]);
    }
}
