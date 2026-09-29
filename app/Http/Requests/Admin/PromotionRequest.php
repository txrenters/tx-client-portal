<?php

namespace App\Http\Requests\Admin;

use App\Models\Promotion;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A promotion as staff write it in the admin area.
 */
class PromotionRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:80'],
            'body' => ['required', 'string', 'max:500'],
            'button_label' => ['required', 'string', 'max:40'],
            // The Refer a friend page, or a web address the button opens.
            'button_link' => ['required', 'string', 'max:255', function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value !== Promotion::LINK_REFERRALS && ! str_starts_with((string) $value, 'https://')) {
                    $fail(__('Choose the Refer a friend page or enter an address starting with https://.'));
                }
            }],
            'audience' => ['required', 'in:all,owner,resident'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'is_active' => ['boolean'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:100'],
            'stop_after_referral' => ['boolean'],
            'theme' => ['sometimes', Rule::in(Promotion::THEMES)],
            'icon' => ['sometimes', Rule::in(Promotion::ICONS)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function promotion(): array
    {
        return [
            ...$this->validated(),
            'priority' => (int) ($this->validated('priority') ?? 0),
            'is_active' => $this->boolean('is_active'),
            'stop_after_referral' => $this->boolean('stop_after_referral'),
        ];
    }
}
