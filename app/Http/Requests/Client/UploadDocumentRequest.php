<?php

namespace App\Http\Requests\Client;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UploadDocumentRequest extends FormRequest
{
    /**
     * The largest file a client may upload, in kilobytes.
     */
    public const MAX_KILOBYTES = 20 * 1024;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.self::MAX_KILOBYTES, 'mimes:pdf,jpg,jpeg,png,heic,heif,doc,docx'],
            'description' => ['nullable', 'string', 'max:255'],
            'attach_to' => ['nullable', 'in:property,portfolio,work_order,lease,account'],
            'target_id' => ['nullable', 'integer'],
            // Older form of choosing a property; kept so existing callers keep working.
            'property_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * Get what the file should be attached to, and which one.
     *
     * @return array{0: ?string, 1: ?int}
     */
    public function target(): array
    {
        if ($this->validated('attach_to') === null && $this->validated('property_id') !== null) {
            return ['property', (int) $this->validated('property_id')];
        }

        $targetId = $this->validated('target_id');

        return [$this->validated('attach_to'), $targetId === null ? null : (int) $targetId];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.mimes' => __('Upload a PDF, photo (JPG, PNG, HEIC) or Word document.'),
            'file.max' => __('Files can be up to 20 MB.'),
        ];
    }
}
