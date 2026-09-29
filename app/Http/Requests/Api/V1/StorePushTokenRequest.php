<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StorePushTokenRequest extends FormRequest
{
    /**
     * Expo mints these as ExponentPushToken[...]; the older ExpoPushToken[...]
     * spelling is still handed out by some SDK versions, so both are taken.
     */
    public const TOKEN_PATTERN = '/^Expo(nent)?PushToken\[[A-Za-z0-9_-]+\]$/';

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:255', 'regex:'.self::TOKEN_PATTERN],
            'platform' => ['required', 'string', 'in:ios,android'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'token.regex' => 'That is not an Expo push token.',
        ];
    }
}
