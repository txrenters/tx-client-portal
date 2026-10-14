<?php

namespace App\Http\Controllers\Resident;

use App\Enums\ClientRole;
use App\Http\Controllers\Concerns\ResolvesClientScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\LeaseLedgerRequest;
use App\Services\Propertyware\LeaseLedger;
use App\Services\Propertyware\PropertywareDirectory;
use App\Services\Propertyware\PropertywareException;
use Inertia\Inertia;
use Inertia\Response;

class LeaseController extends Controller
{
    use ResolvesClientScope;

    /**
     * Show the tenant their lease: its term, their rent, and everything they
     * have been charged and paid on it, over whichever period they pick.
     */
    public function __invoke(LeaseLedgerRequest $request, PropertywareDirectory $directory, LeaseLedger $ledger): Response
    {
        $account = $this->activeClientAccount($request, ClientRole::Resident);
        abort_if($account === null, 403);

        $filters = $request->filters();
        $page = $request->page();

        return Inertia::render('resident/Lease', [
            'filters' => $filters,
            'ledgerAvailable' => $ledger->isAvailable(),
            'lease' => Inertia::defer(function () use ($request, $directory): ?array {
                try {
                    return $directory->leaseFor($this->clientScope($request, ClientRole::Resident, $directory))?->toArray();
                } catch (PropertywareException) {
                    return null;
                }
            })->once(as: 'resident-lease-'.$account->id, until: now()->addMinutes(5)),
            'property' => Inertia::defer(function () use ($request, $directory): ?array {
                try {
                    return ($directory->propertiesFor($this->clientScope($request, ClientRole::Resident, $directory))[0] ?? null)?->toArray();
                } catch (PropertywareException) {
                    return null;
                }
            }, 'property')->once(as: 'resident-lease-property-'.$account->id, until: now()->addMinutes(5)),
            'ledger' => Inertia::defer(function () use ($request, $directory, $ledger, $filters, $page): ?array {
                try {
                    return $ledger->forTenant(
                        $this->clientScope($request, ClientRole::Resident, $directory),
                        from: $filters['from'],
                        to: $filters['to'],
                        page: $page,
                        path: route('resident.lease'),
                    );
                } catch (PropertywareException) {
                    return null;
                }
            }, 'ledger'),
        ]);
    }
}
