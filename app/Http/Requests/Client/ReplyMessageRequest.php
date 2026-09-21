<?php

namespace App\Http\Requests\Client;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class ReplyMessageRequest extends FormRequest
{
    public const MAX_ATTACHMENTS = 5;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'body' => ['required_without:attachments', 'nullable', 'string', 'max:1600'],
            // Photos or files sent to the TexasRenters.com team with the message.
            'attachments' => ['nullable', 'array', 'max:'.self::MAX_ATTACHMENTS],
            'attachments.*' => ['file', 'max:10240', 'mimes:jpg,jpeg,png,gif,webp,heic,heif,pdf,doc,docx'],
            // Sent by the app so a retried send never posts the reply twice.
            'idempotency_key' => ['nullable', 'uuid'],
        ];
    }

    public function idempotencyKey(): string
    {
        return $this->validated('idempotency_key') ?? (string) Str::uuid();
    }
}
