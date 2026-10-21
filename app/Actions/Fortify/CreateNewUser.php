<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Rules\RegisteredClientEmail;
use App\Services\Security\ClientWebPause;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        // Sign-up is a sign-in too, so it waits while staff have the website
        // paused. The apps share this action and carry on.
        if (! request()->is('api/*') && app(ClientWebPause::class)->isPaused()) {
            throw ValidationException::withMessages(['email' => __(ClientWebPause::SIGN_UP_MESSAGE)]);
        }

        Validator::make($input, [
            ...$this->profileRules(),
            // Checked last, and only once the address is otherwise fine,
            // so Propertyware is only asked about real sign-ups.
            'email' => ['bail', ...$this->emailRules(), new RegisteredClientEmail],
            'password' => $this->passwordRules(),
        ])->validate();

        return User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);
    }
}
