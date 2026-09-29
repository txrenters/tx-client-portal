<?php

namespace App\Enums;

/**
 * Where staff are with a client's request for help with the portal or app.
 */
enum HelpRequestStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';

    /**
     * The label clients and staff see.
     */
    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Resolved => 'Resolved',
        };
    }
}
