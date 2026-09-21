<?php

namespace App\Actions\Client;

use App\Models\ClientInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RegisterInvitedClient
{
    public function __construct(private AcceptClientInvitation $acceptInvitation) {}

    /**
     * Create the invited client's login and link it to their Propertyware record.
     *
     * The invitation was emailed to this address, so opening it proves the
     * client owns the email and it starts out verified.
     */
    public function handle(ClientInvitation $invitation, string $name, string $password): User
    {
        return DB::transaction(function () use ($invitation, $name, $password): User {
            $user = User::create([
                'name' => $name,
                'email' => $invitation->email,
                'password' => $password,
            ]);

            $user->forceFill(['email_verified_at' => now()])->save();

            $this->acceptInvitation->handle($invitation, $user);

            return $user;
        });
    }
}
