<?php

namespace App\PortalSso;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Who counts as TexasRenters staff here: the same people the admin gate lets in, i.e. the
 * owners in ADMIN_EMAILS plus anyone given Admin or Staff on the Staff page (User::isStaff()).
 * Only staff use the TR Staff Portal; residents and owners keep their own sign-in untouched.
 */
class StaffAccounts
{
    public static function isStaff(?User $user): bool
    {
        return $user !== null && $user->isStaff();
    }

    public static function isCompanyEmail(?string $email): bool
    {
        $email = Str::lower(trim((string) $email));

        return str_contains($email, '@') && in_array(Str::afterLast($email, '@'), config('portal.allowed_domains'), true);
    }

    /** While the portal is on, staff may only sign in through it (unless the emergency switch is on). */
    public static function mustUsePortal(?User $user): bool
    {
        return config('portal.enabled') && ! config('portal.allow_password_login') && self::isStaff($user);
    }
}
