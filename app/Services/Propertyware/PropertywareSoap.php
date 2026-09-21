<?php

namespace App\Services\Propertyware;

use SoapClient;
use SoapFault;

/**
 * Propertyware's SOAP API, used only where its REST API falls short.
 *
 * Approving a work order is one of those: the REST API has no approval call.
 * Propertyware credits the SOAP login as the approver, whoever asked.
 */
class PropertywareSoap
{
    /** Propertyware's own page size for the read-only ledger. */
    private const LEDGER_PAGE_SIZE = 10;

    /** As far back as one read goes: 200 pages, 2,000 entries. */
    private const LEDGER_MAX_PAGES = 200;

    public function __construct(
        private ?string $url,
        private ?string $username,
        private ?string $password,
    ) {}

    public function isConfigured(): bool
    {
        return filled($this->url) && filled($this->username) && filled($this->password);
    }

    /**
     * Approve a work order, by its Propertyware id, with the given comment.
     *
     * @throws PropertywareException
     */
    public function approveWorkOrder(int $workOrderId, string $approvedDate, string $comment, bool $approved = true): void
    {
        if (! $this->isConfigured()) {
            throw new PropertywareException('Propertyware SOAP credentials are not configured.');
        }

        try {
            $this->client()->approveWorkOrder($workOrderId, $approved, $approvedDate, $comment);
        } catch (SoapFault $fault) {
            throw new PropertywareException('Propertyware refused the approval: '.$fault->getMessage(), previous: $fault);
        }
    }

    /**
     * A lease's ledger: every charge and payment on it, oldest first.
     *
     * Propertyware's REST API has no ledger at all, and its SOAP sync calls
     * (getCharges, getBills) answer nothing for this login, so this read-only
     * search is the only way the portal can show a tenant their history.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws PropertywareException
     */
    public function leaseLedger(int $leaseId, string $from, string $to): array
    {
        if (! $this->isConfigured()) {
            throw new PropertywareException('Propertyware SOAP credentials are not configured.');
        }

        $client = $this->client();
        $items = [];

        // Propertyware pages this ten at a time, newest first, whatever page
        // size we ask for, so the whole history is read page by page.
        for ($page = 1; $page <= self::LEDGER_MAX_PAGES; $page++) {
            try {
                $ledger = $client->getReadOnlyLeaseLedger([
                    'leaseId' => $leaseId,
                    'startDate' => $from.'T00:00:00',
                    'endDate' => $to.'T23:59:59',
                    'transactionType' => null,
                    'pageNumber' => $page,
                    'pageSize' => self::LEDGER_PAGE_SIZE,
                ]);
            } catch (SoapFault $fault) {
                throw new PropertywareException('Propertyware could not read the ledger: '.$fault->getMessage(), previous: $fault);
            }

            /** @var array{items?: array<int, mixed>} $decoded */
            $decoded = json_decode(json_encode($ledger) ?: '[]', true) ?: [];
            $rows = array_values(array_filter($decoded['items'] ?? [], is_array(...)));
            $items = [...$items, ...$rows];

            if (count($rows) < self::LEDGER_PAGE_SIZE) {
                break;
            }
        }

        // The same entry can arrive twice when a page boundary shifts mid-read.
        return array_values(collect($items)->unique(fn (array $item): string => (string) ($item['ID'] ?? spl_object_hash((object) $item)))->all());
    }

    /**
     * Propertyware's chart of accounts, keyed by id.
     *
     * A bill split names a GL account, and only the expense ones are the
     * owner's cost: a split posted to an income account is our own fee
     * recovered from the tenant, which never reaches the owner's statement.
     * The REST API has no accounts endpoint at all, so this is the only source.
     *
     * @return array<int, array{name: string, type: string}>
     *
     * @throws PropertywareException
     */
    public function accounts(): array
    {
        if (! $this->isConfigured()) {
            throw new PropertywareException('Propertyware SOAP credentials are not configured.');
        }

        try {
            $accounts = $this->client()->getAllAccounts();
        } catch (SoapFault $fault) {
            throw new PropertywareException('Propertyware could not read the chart of accounts: '.$fault->getMessage(), previous: $fault);
        }

        /** @var array<int, array<string, mixed>> $decoded */
        $decoded = json_decode(json_encode($accounts) ?: '[]', true) ?: [];

        return collect($decoded)
            ->filter(fn (mixed $account): bool => is_array($account) && filled($account['ID'] ?? null))
            ->mapWithKeys(fn (array $account): array => [(int) $account['ID'] => [
                'name' => (string) ($account['name'] ?? ''),
                'type' => (string) ($account['accountType'] ?? ''),
            ]])
            ->all();
    }

    protected function client(): SoapClient
    {
        return new SoapClient(rtrim((string) $this->url, '?').'?wsdl', [
            'login' => $this->username,
            'password' => $this->password,
            'exceptions' => true,
            'connection_timeout' => 30,
            'cache_wsdl' => WSDL_CACHE_DISK,
        ]);
    }
}
