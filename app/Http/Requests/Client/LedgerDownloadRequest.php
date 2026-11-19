<?php

namespace App\Http\Requests\Client;

use App\Services\Propertyware\LedgerDownloads;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * A tenant's ledger to download: the same period as the Lease page, as a PDF or CSV.
 */
class LedgerDownloadRequest extends LeaseLedgerRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'format' => ['required', Rule::in(LedgerDownloads::FORMATS)],
        ];
    }

    /**
     * The file type asked for.
     */
    public function fileType(): string
    {
        return (string) $this->validated('format');
    }
}
