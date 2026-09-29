<?php

namespace App\Services\Propertyware;

use App\Services\Propertyware\Data\Contact;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Whether an email address is on a Propertyware contact (a tenant, an owner
 * or another contact), which is what lets someone create a portal login on
 * their own. Only tenants and owners are then linked to records and see
 * anything (SyncClientAccounts); anyone else signs in to an empty portal.
 *
 * Vendors never may, even when their address is also on a contact: the
 * portal is for residents and owners, not the companies doing the work.
 *
 * Prospects would count too, but our API key is refused the prospects
 * collection (403), so they cannot be checked until Propertyware grants it.
 *
 * Matched exactly. Cached briefly, so someone fixing a typo in the form does
 * not ask Propertyware again for an address it just answered.
 */
class ClientEmailCheck
{
    private const CACHE_SECONDS = 300;

    /** The vendor list is thousands of records, so it is read hourly. */
    private const VENDOR_CACHE_SECONDS = 3600;

    public function __construct(private PropertywareClient $propertyware) {}

    /**
     * @throws PropertywareException When Propertyware cannot be asked.
     */
    public function isClient(string $email): bool
    {
        $email = Str::lower(trim($email));

        $onContact = Cache::remember('propertyware:client-email:'.hash('sha256', $email), self::CACHE_SECONDS, fn (): bool => collect($this->propertyware->listAll('contacts', ['email' => $email], maxRecords: 200))
            ->map(fn (array $record): Contact => Contact::fromArray($record))
            ->contains(fn (Contact $contact): bool => Str::lower(trim($contact->email ?? '')) === $email));

        return $onContact && ! $this->isVendor($email);
    }

    /**
     * Whether a Propertyware vendor uses this address, as its main or alert email.
     *
     * @throws PropertywareException
     */
    public function isVendor(string $email): bool
    {
        $vendorEmails = Cache::remember('propertyware:vendor-emails', self::VENDOR_CACHE_SECONDS, fn (): array => collect($this->propertyware->listAll('vendors', [], maxRecords: 20000))
            ->flatMap(fn (array $vendor): array => [$vendor['email'] ?? null, $vendor['alertEmail'] ?? null])
            ->filter(fn (mixed $address): bool => is_string($address) && $address !== '')
            ->map(fn (string $address): string => Str::lower(trim($address)))
            ->unique()
            ->values()
            ->all());

        return in_array(Str::lower(trim($email)), $vendorEmails, strict: true);
    }
}
