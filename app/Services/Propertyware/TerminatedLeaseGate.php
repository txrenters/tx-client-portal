<?php

namespace App\Services\Propertyware;

use App\Enums\ClientRole;
use App\Models\ClientAccount;
use App\Models\User;
use App\Services\Propertyware\Data\Lease;

/**
 * Turns away a resident whose tenancy with us is over.
 *
 * A login is shut out only when every account on it is a resident account and
 * every lease on those accounts is terminated in Propertyware. Owners, staff,
 * the demo logins, anyone still on an active lease (eviction, notice given and
 * month-to-month included), and logins not yet linked to a record all get in.
 * When Propertyware cannot be reached the login is let through, so an outage
 * there never locks every resident out.
 */
class TerminatedLeaseGate
{
    public const MESSAGE = 'Your lease has ended, so this account can no longer sign in. Contact TexasRenters.com for help.';

    public function __construct(private PropertywareDirectory $directory) {}

    public function shutsOut(User $user): bool
    {
        if (! config('services.propertyware.block_terminated_leases') || $user->is_demo || $user->isStaff()) {
            return false;
        }

        $accounts = $user->clientAccounts()->get();

        if ($accounts->isEmpty() || $accounts->contains(fn (ClientAccount $account): bool => $account->role !== ClientRole::Resident)) {
            return false;
        }

        try {
            return $accounts->every(fn (ClientAccount $account): bool => $this->onlyTerminatedLeases($account));
        } catch (PropertywareException $exception) {
            report($exception);

            return false;
        }
    }

    /**
     * @throws PropertywareException
     */
    private function onlyTerminatedLeases(ClientAccount $account): bool
    {
        $leases = $this->directory->leasesFor($this->directory->scopeFor($account));

        return $leases !== [] && collect($leases)->every(fn (Lease $lease): bool => $lease->isTerminated());
    }
}
