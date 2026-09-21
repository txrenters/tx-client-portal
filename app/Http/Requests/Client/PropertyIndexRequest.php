<?php

namespace App\Http\Requests\Client;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PropertyIndexRequest extends FormRequest
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
            'occupancy' => ['nullable', 'in:occupied,vacant'],
            'city' => ['nullable', 'string', 'max:100'],
            'bedrooms' => ['nullable', 'in:1,2,3,4'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * Get the property filters the client asked for.
     *
     * @return array{search: ?string, occupancy: ?string, city: ?string, bedrooms: ?string}
     */
    public function filters(): array
    {
        return [
            'search' => $this->validated('search'),
            'occupancy' => $this->validated('occupancy'),
            'city' => $this->validated('city'),
            'bedrooms' => $this->validated('bedrooms'),
        ];
    }

    /**
     * Get the page of properties the client asked for.
     */
    public function page(): int
    {
        return (int) ($this->validated('page') ?? 1);
    }
}
