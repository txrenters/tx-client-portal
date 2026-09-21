<?php

namespace App\Http\Requests\Client;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StartConversationRequest extends FormRequest
{
    public const MAX_ATTACHMENTS = 5;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // The portal asks for a category, so the chatbot can answer the question
            // instead of replying with its numbered menu. Shipped app versions send
            // their own subject line instead, which still opens a thread.
            'category' => ['required_without:subject', 'nullable', 'in:leasing,accounting,maintenance'],
            'subject' => ['required_without:category', 'nullable', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:1600'],
            // Which of an owner's properties the message is about, when they have several.
            'property_id' => ['nullable', 'integer'],
            // Photos or files sent to the TexasRenters.com team with the message.
            'attachments' => ['nullable', 'array', 'max:'.self::MAX_ATTACHMENTS],
            'attachments.*' => ['file', 'max:10240', 'mimes:jpg,jpeg,png,gif,webp,heic,heif,pdf,doc,docx'],
        ];
    }

    public function propertyId(): ?int
    {
        $propertyId = $this->validated('property_id');

        return $propertyId === null ? null : (int) $propertyId;
    }
}
