<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\ClientRole;
use App\Http\Middleware\EnsureClientRole;
use App\Models\ClientAccount;
use App\Services\Propertyware\Data\ClientScope;
use App\Services\Propertyware\Data\Property;
use App\Services\Propertyware\PropertywareDirectory;
use App\Services\Propertyware\PropertywareException;
use Illuminate\Http\Request;

/**
 * Resolves the Propertyware records the signed-in client may see.
 */
trait ResolvesClientScope
{
    /**
     * Build the client's scope, or abort when the login isn't linked to Propertyware.
     */
    protected function clientScope(Request $request, ClientRole $role, PropertywareDirectory $directory): ClientScope
    {
        $account = $this->activeClientAccount($request, $role);

        abort_if($account === null, 403);

        return $directory->scopeFor($account);
    }

    /**
     * The owner's properties as a picker's options, by name. Tenants have the
     * one home their scope already names, so they are never asked.
     *
     * @return array<int, array{id: int, label: string}>
     *
     * @throws PropertywareException
     */
    protected function propertyOptions(ClientScope $scope, PropertywareDirectory $directory): array
    {
        if ($scope->role !== ClientRole::Owner) {
            return [];
        }

        return collect($directory->propertiesFor($scope))
            ->map(fn (Property $property): array => ['id' => $property->id, 'label' => $property->name ?: $property->address])
            ->sortBy('label')->values()->all();
    }

    /**
     * Get the account the client is viewing in whichever portal they're in now.
     */
    protected function currentClientAccount(Request $request): ?ClientAccount
    {
        // A token request from the apps carries no session, so it takes the
        // client's own preferred portal rather than whichever one a browser
        // happened to be looking at.
        $chosen = $request->hasSession()
            ? ClientRole::tryFrom((string) $request->session()->get(EnsureClientRole::SESSION_KEY))
            : null;

        $role = $request->user()->preferredClientRole($chosen);

        return $role === null ? null : $this->activeClientAccount($request, $role);
    }

    /**
     * Get the account the client is viewing in this portal.
     */
    protected function activeClientAccount(Request $request, ClientRole $role): ?ClientAccount
    {
        $selected = $request->hasSession()
            ? $request->session()->get(EnsureClientRole::ACCOUNT_SESSION_KEY.':'.$role->value)
            : null;

        return $request->user()->clientAccountFor($role, $selected === null ? null : (int) $selected);
    }
}
