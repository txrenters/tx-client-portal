<?php

namespace App\Services\Propertyware;

use App\Jobs\SyncLeaseLedger;
use App\Models\LeaseLedgerEntry;
use App\Models\LeaseLedgerSync;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The portal's own copy of a lease's ledger, kept up to date as people visit.
 *
 * Reading a ledger from Propertyware costs one SOAP call per ten entries, so a
 * two-year tenancy is a dozen round trips. The entries are stored here instead:
 * a visit reads the database, and only the days since the last read are fetched
 * again. Propertyware stays the source of truth — nothing is ever written back,
 * and a re-read of the same entry replaces the copy rather than adding to it.
 */
class LeaseLedgerStore
{
    /**
     * Days re-read on every sync, so an entry posted late, or backdated after
     * we last looked, is still picked up.
     */
    private const OVERLAP_DAYS = 14;

    /**
     * How long a lease's copy is trusted before it is refreshed. The first read
     * takes the whole history; after that a day's worth of new entries is all
     * that ever needs fetching.
     */
    private const FRESH_MINUTES = 1440;

    public function __construct(private PropertywareSoap $soap) {}

    /**
     * The lease's entries for a window, as we hold them now.
     *
     * Nothing is fetched here: a read that would go to Propertyware is queued
     * instead, so a page never waits on a tenancy's worth of SOAP calls. The
     * page shows what is held and says the rest is on its way.
     *
     * @return Collection<int, LeaseLedgerEntry>
     */
    public function entries(int $leaseId, string $from, string $to): Collection
    {
        if ($this->isStale($leaseId, $from, $to)) {
            SyncLeaseLedger::dispatch($leaseId, $from, $to);
        }

        return LeaseLedgerEntry::query()
            ->where('lease_id', (string) $leaseId)
            ->whereBetween('occurred_on', [$from, $to])
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Whether a lease's copy is worth refreshing.
     */
    public function isStale(int $leaseId, string $from, string $to): bool
    {
        $state = LeaseLedgerSync::find((string) $leaseId);

        return $state === null || ! $this->isFresh($state, $from, $to);
    }

    /**
     * Whether we hold nothing at all for a lease yet, so a page has numbers
     * still to come rather than numbers that are merely a day old.
     */
    public function isEmpty(int $leaseId): bool
    {
        return ! LeaseLedgerEntry::query()->where('lease_id', (string) $leaseId)->exists();
    }

    /**
     * Fetch what we don't hold yet: the first read covers the whole window,
     * later ones only the days since we last looked.
     *
     * @throws PropertywareException
     */
    public function sync(int $leaseId, string $from, string $to): void
    {
        $state = LeaseLedgerSync::find((string) $leaseId);

        if ($state !== null && $this->isFresh($state, $from, $to)) {
            return;
        }

        // Anything already held starts at its own window, so a visit asking for
        // a longer history still reads the part we never fetched.
        $start = $state === null || $state->synced_from->gt(CarbonImmutable::parse($from))
            ? $from
            : $state->synced_to->subDays(self::OVERLAP_DAYS)->toDateString();

        $items = $this->soap->leaseLedger($leaseId, $start, $to);

        foreach ($items as $item) {
            $entry = $this->present($item);

            if ($entry === null) {
                continue;
            }

            LeaseLedgerEntry::updateOrCreate(
                ['lease_id' => (string) $leaseId, 'external_id' => $entry['external_id']],
                $entry,
            );
        }

        LeaseLedgerSync::updateOrCreate(['lease_id' => (string) $leaseId], [
            'synced_from' => $state === null ? $from : min($from, $state->synced_from->toDateString()),
            'synced_to' => $to,
            'synced_at' => now(),
        ]);
    }

    /**
     * Whether the copy already covers this window and was read recently.
     */
    private function isFresh(LeaseLedgerSync $state, string $from, string $to): bool
    {
        return $state->synced_from->lte(CarbonImmutable::parse($from))
            && $state->synced_to->gte(CarbonImmutable::parse($to))
            && $state->synced_at->gt(now()->subMinutes(self::FRESH_MINUTES));
    }

    /**
     * One Propertyware entry as we store it. Its bank details and account ids
     * stay in Propertyware; only what it was and what it cost is kept.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private function present(array $item): ?array
    {
        $amount = (float) ($item['amount'] ?? 0);
        $externalId = (string) ($item['ID'] ?? '');
        $date = substr((string) ($item['date'] ?? ''), 0, 10);

        if ($externalId === '' || $date === '' || $amount === 0.0) {
            return null;
        }

        $account = is_array($item['account'] ?? null) ? $item['account'] : [];
        $credit = $amount < 0;
        $name = filled($account['name'] ?? null) ? (string) $account['name'] : null;
        $comment = filled($item['comments'] ?? null) ? (string) $item['comments'] : null;
        $kind = $this->kind($account, $credit);

        return [
            'external_id' => $externalId,
            'occurred_on' => $date,
            'kind' => $kind,
            'direction' => $credit ? 'credit' : 'debit',
            'amount' => round(abs($amount), 2),
            'description' => match ($kind) {
                'payment' => 'Payment',
                'return' => 'Payment returned',
                'prepayment' => 'Prepayment',
                'credit' => ($name ?? 'Charge').' credit',
                default => $name ?? 'Charge',
            },
            'detail' => in_array($kind, ['charge', 'return'], true) ? $comment : ($comment ?? $name),
            'reference' => filled($item['referenceNumber'] ?? null) ? (string) $item['referenceNumber'] : null,
        ];
    }

    /**
     * What a ledger line is to the client: something billed, money they sent,
     * a credit back, or prepaid money being moved onto a charge.
     *
     * @param  array<string, mixed>  $account
     */
    private function kind(array $account, bool $credit): string
    {
        if (strtoupper((string) ($account['accountCode'] ?? '')) === 'PREPAY') {
            return 'prepayment';
        }

        $type = (string) ($account['accountType'] ?? '');

        if ($type === 'Income') {
            return $credit ? 'credit' : 'charge';
        }

        // Receivable lines are the money itself: a credit is a payment coming
        // in, a debit is one going back out — an NSF return or a chargeback,
        // which Propertyware posts here rather than as a charge.
        if ($type === 'A/R') {
            return $credit ? 'payment' : 'return';
        }

        return $credit ? 'payment' : 'charge';
    }
}
