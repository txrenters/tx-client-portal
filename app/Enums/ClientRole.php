<?php

namespace App\Enums;

enum ClientRole: string
{
    case Resident = 'resident';
    case Owner = 'owner';

    /**
     * Get the client-facing label for the role's portal.
     */
    public function portalLabel(): string
    {
        return match ($this) {
            self::Resident => 'Resident portal',
            self::Owner => 'Owner portal',
        };
    }

    /**
     * The role with its article, for reading inside a sentence.
     */
    public function article(): string
    {
        return match ($this) {
            self::Resident => 'a resident',
            self::Owner => 'an owner',
        };
    }

    /**
     * Get the name of the role's dashboard route.
     */
    public function dashboardRoute(): string
    {
        return "{$this->value}.dashboard";
    }
}
