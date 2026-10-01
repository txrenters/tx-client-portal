<?php

namespace App\Http\Requests\Client;

use App\Services\Propertyware\MaintenanceSummary;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateWorkOrderRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'property_id' => ['nullable', 'integer'],
            'category' => ['required', 'string', Rule::in(MaintenanceSummary::clientCategories())],
            'description' => ['required', 'string', 'max:2000'],
            'location' => ['nullable', 'string', 'max:255'],
            'urgency' => ['required', 'in:normal,urgent'],
            'entry' => ['nullable', 'in:anytime,scheduled,no'],
            'entry_date' => ['required_if:entry,scheduled', 'nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'entry_time' => ['required_if:entry,scheduled', 'nullable', 'date_format:H:i'],
            'access_notes' => ['nullable', 'string', 'max:500'],
            'photos' => ['nullable', 'array', 'max:'.config('maintenance.max_photos')],
            'photos.*' => ['file', 'max:'.UploadDocumentRequest::MAX_KILOBYTES, 'mimes:pdf,jpg,jpeg,png,heic,heif'],
        ];
    }

    /**
     * Get the work order the client asked for, as MaintenanceSummary::create() takes it.
     *
     * @return array{property_id: ?int, category: string, description: string, location: ?string, urgency: string, entry: ?string, entry_date: ?string, entry_time: ?string, access_notes: ?string}
     */
    public function workOrder(): array
    {
        $propertyId = $this->validated('property_id');

        return [
            'property_id' => $propertyId === null ? null : (int) $propertyId,
            'category' => $this->validated('category'),
            'description' => $this->validated('description'),
            'location' => $this->validated('location'),
            'urgency' => $this->validated('urgency'),
            'entry' => $this->validated('entry'),
            'entry_date' => $this->validated('entry_date'),
            'entry_time' => $this->validated('entry_time'),
            'access_notes' => $this->validated('access_notes'),
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
            'entry_date.required_if' => __('Choose the day we may enter.'),
            'entry_time.required_if' => __('Choose the time we may enter.'),
            'photos.max' => __('Add up to :max photos.', ['max' => config('maintenance.max_photos')]),
            'photos.*.mimes' => __('Photos must be JPG, PNG, HEIC or PDF files.'),
            'photos.*.max' => __('Each photo can be up to 20 MB.'),
        ];
    }
}
