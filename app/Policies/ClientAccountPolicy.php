<?php

namespace App\Policies;

use App\Models\ClientAccount;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ClientAccountPolicy
{
    /**
     * Determine whether the user can view the account's Propertyware records.
     *
     * Another client's account answers as missing, so its id reveals nothing.
     */
    public function view(User $user, ClientAccount $clientAccount): Response
    {
        return $clientAccount->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
