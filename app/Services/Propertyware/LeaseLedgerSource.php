<?php

namespace App\Services\Propertyware;

use Illuminate\Support\Facades\Cache;

/**
 * A lease's ledger, read from Propertyware's REST API.
 *
 * Despite the name, /leases/adjustments is Propertyware's consolidated ledger:
 * it carries the rent and fees billed (income accounts), the money received
 * (receivable accounts) and prepaid money being applied (liability accounts),
 * which is the same set of lines the old SOAP call returned. The narrower
 * /leases/charges and /leases/payments endpoints hold the same money again,
 * broken down, so reading those as well would count every line twice.
 *
 * A charge's own balance lives on /leases/charges as `amountDue`, and the sum
 * of those equals the lease's `leaseBalance` exactly — so the balance is taken
 * from the lease record rather than by adding this ledger up, which does not
 * reconcile once prepayments are applied.
 *
 * Every row names a GL account, so the chart of accounts gives a line its real
 * description ("Rent", "Late Fee") instead of a generic one.
 */
class LeaseLedgerSource
{
    /** Propertyware's consolidated ledger for a lease. */
    private const LEDGER = 'leases/adjustments';

    public function __construct(private PropertywareClient $propertyware) {}

    public function isConfigured(): bool
    {
        return $this->propertyware->isConfigured();
    }

    /**
     * Every ledger entry Propertyware holds for one lease.
     *
     * Yielded one at a time: a long tenancy runs to hundreds of lines, and an
     * owner import walks every lease they hold, so nothing is served by
     * holding a whole history and the payload it came from at once.
     *
     * @return iterable<int, array<string, mixed>>
     *
     * @throws PropertywareException
     */
    public function entries(int $leaseId): iterable
    {
        $accounts = $this->accounts();

        foreach ($this->propertyware->listAll(self::LEDGER, ['leaseID' => $leaseId]) as $row) {
            $entry = $this->present($row, $accounts);

            if ($entry !== null) {
                yield $entry;
            }
        }
    }

    /**
     * One Propertyware row as the portal stores it. Bank accounts, payer names
     * and GL ids stay in Propertyware; only what the line was and what it cost
     * is kept.
     *
     * @param  array<string, mixed>  $row
     * @param  array<int, array{name: string, type: string}>  $accounts
     * @return array<string, mixed>|null
     */
    private function present(array $row, array $accounts): ?array
    {
        $amount = (float) ($row['amount'] ?? 0);
        $id = (string) ($row['id'] ?? '');
        $date = substr((string) ($row['date'] ?? ''), 0, 10);

        if ($id === '' || $date === '' || $amount === 0.0) {
            return null;
        }

        // Propertyware signs the ledger from the tenant's account: a charge is
        // positive, money coming in is negative.
        $credit = $amount < 0;
        $account = $accounts[(int) ($row['glAccountID'] ?? 0)] ?? null;
        $name = filled($account['name'] ?? null) ? (string) $account['name'] : null;
        $comment = filled($row['comments'] ?? null) ? (string) $row['comments'] : null;
        $kind = $this->kind($name, (string) ($account['type'] ?? ''), $credit);

        return [
            'external_id' => $id,
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
            'reference' => filled($row['refNo'] ?? null) ? (string) $row['refNo'] : null,
        ];
    }

    /**
     * What a ledger line is to the client: something billed, money they sent,
     * a credit back, or prepaid money being moved onto a charge.
     */
    private function kind(?string $name, string $type, bool $credit): string
    {
        // Prepaid money being applied is the tenant's own, so it belongs on
        // neither side of the totals.
        if ($name !== null && str_contains(strtolower($name), 'prepay')) {
            return 'prepayment';
        }

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

    /**
     * Propertyware's chart of accounts, keyed by id and read once a day.
     *
     * @return array<int, array{name: string, type: string}>
     */
    private function accounts(): array
    {
        return Cache::remember('propertyware:gl-accounts', now()->addDay(), function (): array {
            try {
                return collect($this->propertyware->listAll('accounting/glaccounts', [], 2000))
                    ->filter(fn (array $account): bool => filled($account['id'] ?? null))
                    ->mapWithKeys(fn (array $account): array => [(int) $account['id'] => [
                        'name' => (string) ($account['name'] ?? ''),
                        'type' => (string) ($account['accountType'] ?? ''),
                    ]])
                    ->all();
            } catch (PropertywareException) {
                return [];
            }
        });
    }
}
