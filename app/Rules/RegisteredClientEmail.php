<?php

namespace App\Rules;

use App\Models\ClientInvitation;
use App\Services\Propertyware\ClientEmailCheck;
use App\Services\Propertyware\PropertywareException;
use App\Services\Security\IpGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Only people TexasRenters.com knows may create a login on their own: the
 * email must be on a Propertyware contact (see ClientEmailCheck).
 *
 * Let through without asking: the ADMIN_EMAILS owners, and anyone with an
 * invitation waiting (staff already chose their record). Staff accounts are
 * created in the admin area and never come through here.
 *
 * Refused when Propertyware cannot be asked, rather than letting anyone in.
 * The answer does say whether an address is a client's, so the lookups are
 * rate limited per address and refusals are logged for the Security page.
 */
class RegisteredClientEmail implements ValidationRule
{
    /** Lookups one IP address may make in a minute. */
    private const PER_MINUTE = 10;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! config('services.propertyware.require_client_email') || ! is_string($value)) {
            return;
        }

        $email = Str::lower(trim($value));

        if (in_array($email, config('services.admin_emails', []), strict: true)
            || ClientInvitation::query()->pending()->where('email', $email)->exists()) {
            return;
        }

        $key = 'register-check:'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, self::PER_MINUTE)) {
            $fail(__('Too many attempts. Please wait a minute and try again.'));

            return;
        }

        RateLimiter::hit($key, 60);

        try {
            $isClient = app(ClientEmailCheck::class)->isClient($email);
        } catch (PropertywareException) {
            $fail(__('We cannot check our records right now. Please try again in a few minutes.'));

            return;
        }

        if (! $isClient) {
            app(IpGuard::class)->record(request(), 'unknown_registration', ['email' => $email]);

            $fail(__('We could not find this email in our records. Please use the email address registered with us in Propertyware. If you are having trouble, text us at (281) 248-8018 for a faster answer.'));
        }
    }
}
