<?php

namespace App\Http\Requests\Api\V1;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Rules\RegisteredClientEmail;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->profileRules(),
            // The same check as the web: only Propertyware clients sign up.
            'email' => ['bail', ...$this->emailRules(), new RegisteredClientEmail],
            'password' => $this->passwordRules(),
            'device_name' => ['required', 'string', 'max:255'],
        ];
    }
}
