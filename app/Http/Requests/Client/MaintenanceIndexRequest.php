<?php

namespace App\Http\Requests\Client;

use App\Enums\WorkOrderStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MaintenanceIndexRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::in(['all', ...array_column(WorkOrderStatus::cases(), 'value')])],
            // Owners only: just the work orders waiting on their approval.
            'approval' => ['nullable', 'in:needed'],
            'search' => ['nullable', 'string', 'max:100'],
            'property' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * Get the list filters the client asked for.
     *
     * With no status in the URL, $defaultStatus applies; "all" shows every status.
     *
     * @return array{status: ?string, search: ?string, property: ?string, approval: ?string}
     */
    public function filters(?string $defaultStatus = null): array
    {
        $status = $this->validated('status') ?? $defaultStatus;

        return [
            'status' => $status === 'all' ? null : $status,
            'approval' => $this->validated('approval'),
            'search' => $this->validated('search'),
            'property' => $this->validated('property'),
        ];
    }

    /**
     * Get the page of work orders the client asked for.
     */
    public function page(): int
    {
        return (int) ($this->validated('page') ?? 1);
    }
}
