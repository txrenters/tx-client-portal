<?php

namespace App\Models;

use Database\Factories\WorkOrderApprovalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An owner's approve or decline decision on a work order, made in the portal.
 *
 * @property string $reference
 * @property string $work_order_id
 * @property ?string $work_order_number
 * @property string $decision
 * @property ?string $note
 * @property string $approver_name
 * @property string $propertyware_contact_id
 * @property Carbon $decided_at
 * @property ?Carbon $propertyware_synced_at When Propertyware accepted the approval.
 * @property ?string $sync_error
 */
#[Fillable([
    'reference', 'user_id', 'client_account_id', 'propertyware_contact_id', 'work_order_id', 'work_order_number',
    'decision', 'note', 'approver_name', 'ip_address', 'user_agent', 'decided_at',
    'propertyware_synced_at', 'sync_error',
])]
class WorkOrderApproval extends Model
{
    /** @use HasFactory<WorkOrderApprovalFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<ClientAccount, $this>
     */
    public function clientAccount(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class);
    }

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
            'propertyware_synced_at' => 'datetime',
        ];
    }
}
