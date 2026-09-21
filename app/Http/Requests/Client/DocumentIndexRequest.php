<?php

namespace App\Http\Requests\Client;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class DocumentIndexRequest extends FormRequest
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
            'category' => ['nullable', 'string', 'max:50'],
            'type' => ['nullable', 'in:images,pdf,other'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'property' => ['nullable', 'integer'],
            'work_order' => ['nullable', 'string', 'max:20'],
            // Which date preset the web page shows; the dates above do the filtering.
            'range' => ['nullable', 'in:30,90,365,year,custom'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * Get the document filters the client asked for.
     *
     * @return array{search: ?string, category: ?string, type: ?string, from: ?string, to: ?string, range: ?string, property: ?int, work_order: ?string}
     */
    public function filters(): array
    {
        return [
            'search' => $this->validated('search'),
            'category' => $this->validated('category'),
            'type' => $this->validated('type'),
            'from' => $this->validated('from'),
            'to' => $this->validated('to'),
            'range' => $this->validated('range'),
            'property' => $this->validated('property') === null ? null : (int) $this->validated('property'),
            'work_order' => $this->validated('work_order'),
        ];
    }

    /**
     * Get the page of documents the client asked for.
     */
    public function page(): int
    {
        return (int) ($this->validated('page') ?? 1);
    }
}
