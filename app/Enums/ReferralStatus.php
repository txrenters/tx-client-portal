<?php

namespace App\Enums;

/**
 * Where staff are with an owner a client referred.
 */
enum ReferralStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Signed = 'signed';
    case NotAFit = 'not_a_fit';

    /**
     * The label clients and staff see.
     */
    public function label(): string
    {
        return match ($this) {
            self::New => 'Received',
            self::Contacted => 'Contacted',
            self::Signed => 'Signed',
            self::NotAFit => 'Not a fit',
        };
    }
}
