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
    public const MAX_ATTACHMENTS = 5;

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
            // Screenshots of what went wrong, mostly; the same files Messages takes, less Word.
            'attachments' => ['nullable', 'array', 'max:'.self::MAX_ATTACHMENTS],
            'attachments.*' => ['file', 'max:10240', 'mimes:jpg,jpeg,png,gif,webp,heic,heif,pdf'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'message.min' => __('Tell us a little more so we can help.'),
            'attachments.max' => __('Attach up to :max files.', ['max' => self::MAX_ATTACHMENTS]),
        ];
    }
}
