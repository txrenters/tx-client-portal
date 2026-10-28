<?php

namespace App\Services\Propertyware;

use SoapClient;
use SoapFault;

/**
 * Propertyware's SOAP API, kept for work order approvals, which its REST API
 * can neither make nor fully read.
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

    /**
     * Read a work order's approval, by its work order number: whether it is
     * approved, who Propertyware credits, and the approval comment. REST
     * reports only the approved flag and date.
     *
     * @return ?array{approved: bool, approvedDate: ?string, approvedBy: ?string, approvedByEmail: ?string, comment: ?string}
     *
     * @throws PropertywareException
     */
    public function workOrderApproval(int $workOrderNumber): ?array
    {
        if (! $this->isConfigured()) {
            throw new PropertywareException('Propertyware SOAP credentials are not configured.');
        }

        try {
            $workOrder = $this->client()->getWorkOrder([
                'workOrderNumber' => $workOrderNumber,
                'orderByNewestFirst' => true,
                'pageNumber' => 1,
                'pageSize' => 1,
            ]);
        } catch (SoapFault $fault) {
            throw new PropertywareException('Propertyware could not read the work order: '.$fault->getMessage(), previous: $fault);
        }

        if (! is_object($workOrder) || (int) ($workOrder->number ?? 0) !== $workOrderNumber) {
            return null;
        }

        $approver = is_object($workOrder->approvedBy ?? null) ? $workOrder->approvedBy : null;
        $approverName = trim(($approver->firstName ?? '').' '.($approver->lastName ?? ''));

        return [
            'approved' => (bool) ($workOrder->approved ?? false),
            'approvedDate' => filled($workOrder->approvedDate ?? null) ? substr((string) $workOrder->approvedDate, 0, 10) : null,
            'approvedBy' => $approverName !== '' ? $approverName : null,
            'approvedByEmail' => filled($approver->email ?? null) ? (string) $approver->email : null,
            'comment' => filled($workOrder->approvalComment ?? null) ? trim((string) $workOrder->approvalComment) : null,
        ];
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
