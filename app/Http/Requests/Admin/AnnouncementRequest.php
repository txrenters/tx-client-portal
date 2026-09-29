<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An announcement as staff write it in the admin area.
 */
class AnnouncementRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:2000'],
            'audience' => ['required', 'in:all,owner,resident'],
            'tone' => ['required', 'in:info,warning'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:now', Rule::when($this->filled('starts_at'), 'after:starts_at')],
            'send_email' => ['boolean'],
            'send_push' => ['boolean'],
        ];
    }

    /**
     * The columns to save, without the one-off send choices.
     *
     * @return array<string, mixed>
     */
    public function announcement(): array
    {
        return $this->safe()->only(['title', 'body', 'audience', 'tone', 'starts_at', 'ends_at']);
    }
}
