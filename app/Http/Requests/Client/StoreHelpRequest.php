<?php

namespace App\Http\Requests\Client;

use App\Enums\HelpTopic;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A client asking for help with the portal or the app. The web page and the
 * API share it; the apps also say which platform and build they are.
 */
class StoreHelpRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'topic' => ['required', Rule::enum(HelpTopic::class)],
            'message' => ['required', 'string', 'min:10', 'max:3000'],
            'platform' => ['nullable', 'string', 'in:ios,android'],
            'app_version' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'message.min' => __('Tell us a little more so we can help.'),
        ];
    }
}
