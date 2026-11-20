<?php

namespace App\Http\Requests\Client;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A meeting to book: which one, when, who it is for, and anything the client
 * wants us to know. The page fills the name and email in from the account,
 * and the client may change them; left out, the account's own are used.
 */
class BookMeetingRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'event_type' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'date', 'after:now'],
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'timezone' => ['nullable', 'timezone:all'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'starts_at.after' => 'That time has already passed. Please pick another.',
        ];
    }
}
