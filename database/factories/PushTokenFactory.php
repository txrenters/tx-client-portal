<?php

namespace Database\Factories;

use App\Models\PushToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PushToken>
 */
class PushTokenFactory extends Factory
{
    protected $model = PushToken::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'token' => 'ExponentPushToken['.$this->faker->unique()->regexify('[A-Za-z0-9_-]{22}').']',
            'platform' => 'ios',
            'device_name' => 'iPhone',
        ];
    }

    public function android(): static
    {
        return $this->state(fn (): array => ['platform' => 'android', 'device_name' => 'Pixel']);
    }
}
