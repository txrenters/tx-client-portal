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
            // Clients can no longer turn texts off. Apps already installed still
            // send this, so it is accepted and ignored rather than refused.
            'message_replies_sms' => ['sometimes', 'boolean'],
        ];
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
