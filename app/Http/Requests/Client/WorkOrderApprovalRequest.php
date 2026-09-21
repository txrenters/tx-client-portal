<?php

namespace App\Http\Requests\Client;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class WorkOrderApprovalRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'in:approved,declined'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
