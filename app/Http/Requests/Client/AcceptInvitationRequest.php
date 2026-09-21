<?php

namespace App\Http\Requests\Client;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AcceptInvitationRequest extends FormRequest
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * Signed-in users only confirm the invitation; new clients choose a name and password.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        if ($this->user() !== null) {
            return [];
        }

        return [
            'name' => $this->nameRules(),
            'password' => $this->passwordRules(),
        ];
    }
}
