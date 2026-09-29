<?php

namespace App\Jobs;

use App\Models\Referral;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Send a referral to the website's owner-leads webhook (n8n), in the same
 * shape the website's lead form posts, so it lands in the same pipeline as a
 * lead from the website.
 *
 * Queued, so a slow or failing webhook never holds up the client's form, and
 * retried, since a lead lost to a blip is a lost owner.
 */
class SendReferralToLeads implements ShouldQueue
{
    use Queueable;

    /** The website's option for this, so n8n files it with the other customer referrals. */
    public const SOURCE = 'Referral from current customer';

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(public int $referralId) {}

    public function handle(): void
    {
        $url = config('services.leads.webhook_url');
        $referral = Referral::find($this->referralId);

        if (blank($url) || $referral === null || $referral->lead_sent_at !== null) {
            return;
        }

        try {
            $response = Http::acceptJson()->timeout(20)->post($url, [
                'first_name' => $referral->first_name,
                'last_name' => $referral->last_name,
                'email_address' => $referral->email,
                'phone_number' => $referral->phone,
                'sources' => [self::SOURCE],
                'referred_by' => $referral->referrer_name,
                'rental_property_address' => $referral->property_address,
                'rental_property_details' => $referral->property_details ?? '',
            ]);
        } catch (ConnectionException $exception) {
            $referral->update(['lead_error' => 'Could not reach the leads webhook.']);

            throw $exception;
        }

        if ($response->failed()) {
            $referral->update(['lead_error' => "The leads webhook answered {$response->status()}."]);

            throw new RuntimeException("The leads webhook answered {$response->status()} for referral {$referral->id}.");
        }

        $referral->update(['lead_sent_at' => now(), 'lead_error' => null]);
    }

    public function failed(?Throwable $exception): void
    {
        report($exception);
    }
}
