<?php

namespace App\Http\Requests\Client;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ConversationIndexRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            // A building id, or "account" for conversations on the client's own record.
            'property' => ['nullable', 'regex:/^(account|\d+)$/'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * Get the conversation filters the client asked for.
     *
     * @return array{search: ?string, property: int|string|null}
     */
    public function filters(): array
    {
        $property = $this->validated('property');

        return [
            'search' => $this->validated('search'),
            'property' => $property === null || $property === 'account' ? $property : (int) $property,
        ];
    }

    /**
     * Get the page of conversations the client asked for.
     */
    public function page(): int
    {
        return (int) ($this->validated('page') ?? 1);
    }
}
