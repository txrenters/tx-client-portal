<?php

namespace App\Services\Propertyware;

use SoapClient;
use SoapFault;

/**
 * Propertyware's SOAP API, kept for the one thing its REST API cannot do.
 *
 * Approving a work order has no REST equivalent, and SOAP authenticates with a
 * Propertyware user login rather than the REST API key, so it can still write
 * while that key is read-only. Everything else the portal reads — the ledger,
 * bills and the chart of accounts — comes from REST.
 *
 * Propertyware credits the SOAP login as the approver, whoever asked.
 */
class PropertywareSoap
{
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
