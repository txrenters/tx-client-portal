<?php

namespace App\Services\Leases;

use App\Enums\ClientRole;
use App\Models\ClientAccount;
use Carbon\CarbonImmutable;

/**
 * Turns an expiring lease into what one person reads, on the lock screen and
 * in the app's notification list.
 *
 * The only place this wording is built. The owner's version is assembled from
 * the property and the dates alone; the tenant's is the office's letter,
 * addressed to the tenant reading it and to nobody else on the lease.
 */
final class LeaseNoticeText
{
    /**
     * @return array<string, mixed>
     */
    public static function for(ExpiringLease $lease, ClientAccount $account, CarbonImmutable $today): array
    {
        $owner = $account->role === ClientRole::Owner;
        $greetingName = trim((string) $account->user?->name);
        $days = $lease->daysLeft($today);
        $end = $lease->endDate->format('M j, Y');

        $details = implode("\n", array_filter([
            "Building Name: {$lease->building}",
            "Unit Name: {$lease->unit}",
            $lease->startDate === null ? null : 'Lease Start Date: '.$lease->startDate->format('m/d/Y'),
            'Lease Expiration Date: '.$lease->endDate->format('m/d/Y'),
        ]));

        if ($owner) {
            $title = "Lease ending at {$lease->building}";
            $body = "The lease ends on {$end}, {$days} days from now. It's time to decide whether to renew it or list the property on the market.";
            $message = "{$body}\n\n{$details}";
            $url = route('owner.properties.show', $lease->buildingId);
        } else {
            $deadline = $lease->renewalDeadline()->format('M j, Y');
            $title = "Your lease ends {$end}";
            $body = "To renew, your documents must be submitted and approved by {$deadline}. After that, the home goes back on the market.";
            $message = implode("\n\n", [
                $greetingName === '' ? 'Dear Resident,' : "Dear {$greetingName},",
                "This notification is to inform you that your lease will be expiring soon. The details of your current lease are shown below:\n\n{$details}",
                "Per your lease agreement, your home will be placed back on the market 60 days before the lease ends. If you've been offered a renewal or extension, all required documents must be submitted and approved at least 60 days prior to your lease end date.",
                "Best regards,\nThe TexasRenters.com Property Management Team",
            ]);
            $url = route('resident.lease');
        }

        $notice = [
            'type' => 'lease_expiring',
            'role' => $account->role->value,
            'account_id' => $account->id,
            'lease_id' => $lease->id,
            'building_id' => $lease->buildingId,
            'building' => $lease->building,
            'unit' => $lease->unit,
            'start_date' => $lease->startDate?->toDateString(),
            'end_date' => $lease->endDate->toDateString(),
            'renewal_deadline' => $lease->renewalDeadline()->toDateString(),
            'days_left' => $days,
            'title' => $title,
            'body' => $body,
            'message' => $message,
            'url' => $url,
        ];

        if (! $owner) {
            $notice['greeting_name'] = $greetingName;
        }

        return $notice;
    }
}
