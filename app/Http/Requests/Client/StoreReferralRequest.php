<?php

namespace App\Http\Requests\Client;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A property owner a client is referring. The same fields as the website's
 * owner enquiry, less "How did you hear about us?", which is the referral.
 */
class StoreReferralRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:30', 'regex:/\d{3}.*\d{3}.*\d{4}/'],
            'property_address' => ['required', 'string', 'max:255'],
            'property_details' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Get the referred owner's details, as ReferralProgram::submit() takes them.
     *
     * @return array{first_name: string, last_name: string, email: string, phone: string, property_address: string, property_details: ?string}
     */
    public function details(): array
    {
        return [
            'first_name' => $this->validated('first_name'),
            'last_name' => $this->validated('last_name'),
            'email' => $this->validated('email'),
            'phone' => $this->validated('phone'),
            'property_address' => $this->validated('property_address'),
            'property_details' => $this->validated('property_details'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => __('Enter a phone number with its area code.'),
        ];
    }
}
