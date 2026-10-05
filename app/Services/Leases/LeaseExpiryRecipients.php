<?php

namespace App\Services\Leases;

use App\Enums\ClientRole;
use App\Models\ClientAccount;
use App\Services\Propertyware\Data\Contact;
use App\Services\Propertyware\PropertywareDirectory;
use App\Services\Propertyware\PropertywareException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;

/**
 * The portal accounts a lease's reminder goes to: every owner of its
 * portfolio, and every tenant named on it who has a login.
 *
 * Owners are matched the way the portal decides which properties an owner
 * may see — through the portfolios on their Propertyware contact — so the
 * reminder reaches exactly the owners who can open that property.
 *
 * Recipients are resolved in full: an owner contact Propertyware has no
 * record of (404) is skipped, but any other lookup failure is thrown so the
 * caller sends and records nothing for the lease and retries next run.
 */
class LeaseExpiryRecipients
{
    /** @var array<int, array<int, ClientAccount>>|null portfolio id → owner accounts, built once per run */
    private ?array $ownersByPortfolio = null;

    public function __construct(private PropertywareDirectory $directory) {}

    /**
     * @return Collection<int, ClientAccount>
     */
    public function owners(ExpiringLease $lease): Collection
    {
        return collect($this->ownersByPortfolio()[$lease->portfolioId] ?? []);
    }

    /**
     * @return Collection<int, ClientAccount>
     */
    public function tenants(ExpiringLease $lease): Collection
    {
        if ($lease->tenantContactIds === []) {
            return collect();
        }

        return ClientAccount::query()
            ->where('role', ClientRole::Resident)
            ->whereIn('propertyware_id', $lease->tenantContactIds)
            ->with('user')
            ->get();
    }

    /**
     * @return array<int, array<int, ClientAccount>>
     */
    private function ownersByPortfolio(): array
    {
        if ($this->ownersByPortfolio !== null) {
            return $this->ownersByPortfolio;
        }

        $map = [];

        foreach (ClientAccount::query()->where('role', ClientRole::Owner)->with('user')->get() as $account) {
            $contact = $this->contactOrNull($account->propertyware_id);

            if ($contact?->role !== ClientRole::Owner) {
                continue;
            }

            foreach ($contact->portfolioIds as $portfolioId) {
                $map[$portfolioId][] = $account;
            }
        }

        return $this->ownersByPortfolio = $map;
    }

    /**
     * @throws PropertywareException when the lookup fails for any reason other than a 404
     */
    private function contactOrNull(string $contactId): ?Contact
    {
        try {
            return $this->directory->findContact($contactId);
        } catch (PropertywareException $exception) {
            $previous = $exception->getPrevious();

            if ($previous instanceof RequestException && $previous->response->status() === 404) {
                return null;
            }

            throw $exception;
        }
    }
}
