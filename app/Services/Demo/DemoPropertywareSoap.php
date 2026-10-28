<?php

namespace App\Services\Demo;

use App\Services\Propertyware\PropertywareSoap;

/**
 * SOAP for the demo logins: an approval is recorded in the portal and goes no further.
 */
final class DemoPropertywareSoap extends PropertywareSoap
{
    public function __construct()
    {
        parent::__construct('https://propertyware-soap.demo.invalid', 'demo', 'demo');
    }

    public function approveWorkOrder(int $workOrderId, string $approvedDate, string $comment, bool $approved = true): void {}

    public function workOrderApproval(int $workOrderNumber): ?array
    {
        return null;
    }
}
