<?php

namespace App\Http\Requests\Client;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class LeaseLedgerRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            // Which preset the web page shows; the dates do the filtering.
            'range' => ['nullable', 'string', 'max:20'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    /**
     * Get the period the tenant asked to see.
     *
     * @return array{range: ?string, from: ?string, to: ?string}
     */
    public function filters(): array
    {
        return [
            'range' => $this->validated('range'),
            'from' => $this->validated('from'),
            'to' => $this->validated('to'),
        ];
    }

    /**
     * Get the page of transactions the tenant asked for.
     */
    public function page(): int
    {
        return (int) ($this->validated('page') ?? 1);
    }
}
