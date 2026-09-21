<?php

namespace App\Actions\Client;

use App\Enums\ClientRole;
use App\Models\ClientInvitation;
use App\Notifications\ClientInvitationNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class InviteClient
{
    /**
     * The number of days an invitation link stays valid.
     */
    public const EXPIRES_AFTER_DAYS = 7;

    /**
     * Create an invitation for a Propertyware client and email them the setup link.
     */
    public function handle(string $email, string $name, ClientRole $role, string $propertywareId): ClientInvitation
    {
        $token = Str::random(64);

        $invitation = ClientInvitation::create([
            'email' => Str::lower($email),
            'name' => $name,
            'role' => $role,
            'propertyware_id' => $propertywareId,
            'token_hash' => ClientInvitation::hashToken($token),
            'expires_at' => now()->addDays(self::EXPIRES_AFTER_DAYS),
        ]);

        Notification::route('mail', $invitation->email)
            ->notify(new ClientInvitationNotification($invitation, $token));

        return $invitation;
    }
}
