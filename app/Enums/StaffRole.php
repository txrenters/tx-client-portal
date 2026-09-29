<?php

namespace App\Enums;

/**
 * Staff access granted from the admin area. The owners listed in ADMIN_EMAILS
 * sit above both and are never stored as one of these.
 */
enum StaffRole: string
{
    /** The whole admin area, including adding and removing staff. */
    case Admin = 'admin';

    /** The whole admin area except managing staff. */
    case Staff = 'staff';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Staff => 'Staff',
        };
    }
}
