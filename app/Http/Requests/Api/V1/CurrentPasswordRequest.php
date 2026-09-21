<?php

namespace App\Http\Requests\Api\V1;

use App\Concerns\PasswordValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Re-checks the user's password before a sensitive account change.
 *
 * The web portal asks for this through its password confirmation screen;
 * the apps send it with the request instead.
 */
class CurrentPasswordRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'password' => $this->currentPasswordRules(),
        ];
    }
}
