<?php

namespace App\Actions\Client;

use App\Models\ClientAccount;
use App\Models\ClientInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AcceptClientInvitation
{
    /**
     * Link the user to the invited Propertyware record and consume the invitation.
     *
     * @throws ValidationException
     */
    public function handle(ClientInvitation $invitation, User $user): ClientAccount
    {
        return DB::transaction(function () use ($invitation, $user): ClientAccount {
            $invitation = ClientInvitation::query()
                ->pending()
                ->lockForUpdate()
                ->find($invitation->id);

            if ($invitation === null) {
                throw ValidationException::withMessages([
                    'invitation' => __('This invitation is no longer valid.'),
                ]);
            }

            $account = $user->clientAccounts()->firstOrCreate(
                ['role' => $invitation->role, 'propertyware_id' => $invitation->propertyware_id],
                ['label' => $invitation->name],
            );

            $invitation->forceFill(['accepted_at' => now()])->save();

            return $account;
        });
    }
}
