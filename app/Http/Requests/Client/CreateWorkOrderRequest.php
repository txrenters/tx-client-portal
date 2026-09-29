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
