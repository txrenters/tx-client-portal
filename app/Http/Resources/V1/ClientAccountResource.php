<?php

namespace App\Http\Resources\V1;

use App\Models\ClientAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ClientAccount
 */
class ClientAccountResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role->value,
            'portal' => $this->role->portalLabel(),
            'label' => $this->label ?? 'Account '.$this->id,
            'reference' => $this->propertyware_id,
            // Propertyware's own portal for this account's role: owner or tenant.
            'propertywarePortalUrl' => config("services.propertyware.portal_urls.{$this->role->value}"),
        ];
    }
}
