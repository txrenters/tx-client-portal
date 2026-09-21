<?php

namespace App\Http\Requests\Settings;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class NotificationPreferencesRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...collect(User::NOTIFICATION_DEFAULTS)->map(fn (): array => ['sometimes', 'boolean'])->all(),
            // Kept in the messaging hub, not here: it decides what gets texted.
            'message_replies_sms' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Whether the client changed texted replies on this save.
     */
    public function smsReplies(): ?bool
    {
        return $this->has('message_replies_sms') ? $this->boolean('message_replies_sms') : null;
    }

    /**
     * The settings to save, merged over what the client already has.
     *
     * @return array<string, bool>
     */
    public function preferences(): array
    {
        return [
            ...$this->user()->notificationPreferences(),
            ...array_map(
                fn (mixed $value): bool => (bool) $value,
                array_intersect_key($this->validated(), User::NOTIFICATION_DEFAULTS),
            ),
        ];
    }
}
