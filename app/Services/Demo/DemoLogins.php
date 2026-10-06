<?php

namespace App\Services\Demo;

use App\Enums\ClientRole;

/**
 * The App Review logins: who they are and which made-up contact each one is.
 */
final class DemoLogins
{
    /**
     * Keyed by the setting suffix that holds each login's password hash.
     *
     * @var array<string, array{email: string, name: string, role: ClientRole, contact: int}>
     */
    public const ALL = [
        'owner' => ['email' => 'appreview.owner@texasrenters.com', 'name' => 'Demo Owner', 'role' => ClientRole::Owner, 'contact' => DemoData::OWNER_CONTACT],
        'tenant' => ['email' => 'appreview.tenant@texasrenters.com', 'name' => 'Demo Tenant', 'role' => ClientRole::Resident, 'contact' => DemoData::TENANT_CONTACT],
    ];

    public static function hashKey(string $key): string
    {
        return "demo.password_hash.{$key}";
    }
}
