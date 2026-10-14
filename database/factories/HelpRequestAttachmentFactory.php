<?php

namespace Database\Factories;

use App\Models\HelpRequest;
use App\Models\HelpRequestAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HelpRequestAttachment>
 */
class HelpRequestAttachmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'help_request_id' => HelpRequest::factory(),
            'path' => 'help-requests/'.fake()->uuid().'.png',
            'name' => 'screenshot.png',
            'mime_type' => 'image/png',
            'size' => 2048,
        ];
    }
}
