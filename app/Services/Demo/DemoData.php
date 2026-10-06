<?php

namespace App\Services\Demo;

use Carbon\CarbonImmutable;

/**
 * The made-up records the App Review logins see, shaped exactly like
 * Propertyware's REST API and the chat hub return them, so the real services
 * read them unchanged.
 *
 * Dates count from today: the lease always ends within the 90-day reminder
 * window and the ledger always covers the last six months. Every id is 9
 * digits from 900000000, far past Propertyware's own. Names and addresses are
 * invented; "Demo" is in each so nobody mistakes them for a client.
 */
final class DemoData
{
    public const OWNER_CONTACT = 900000001;

    public const TENANT_CONTACT = 900000002;

    public const PORTFOLIO = 900000010;

    public const HOME_LEASED = 900000021;

    public const HOME_VACANT = 900000022;

    public const LEASE = 900000031;

    public const VENDOR = 900000041;

    public const WORK_ORDER_DONE = 900000051;

    public const WORK_ORDER_AC = 900000052;

    public const WORK_ORDER_APPROVAL = 900000053;

    public const WORK_ORDER_GARAGE = 900000054;

    public const DOC_LEASE = 900000061;

    public const DOC_STATEMENT = 900000062;

    public const DOC_INSPECTION = 900000063;

    public const DOC_INVOICE = 900000064;

    public const DOC_WELCOME = 900000065;

    public const OWNER_THREAD = 900000071;

    public const TENANT_THREAD = 900000072;

    public const RENT = 1850.0;

    public const MANAGEMENT_FEE = 148.0;

    /**
     * How many days after today the lease ends: inside the 90-day reminder window.
     */
    public const LEASE_DAYS_LEFT = 75;

    private const GL_RENT = 4000;

    private const GL_FEES = 4201;

    private const GL_REPAIRS = 5100;

    private const GL_RECEIVABLE = 1000;

    private const GL_BANK = 1100;

    private const OFFICE = 'TexasRenters Office';

    /**
     * @return array<string, mixed>|null
     */
    public function contact(string|int $id): ?array
    {
        return match ((int) $id) {
            self::OWNER_CONTACT => [
                'id' => (string) self::OWNER_CONTACT, 'type' => 'OWNER', 'firstName' => 'Demo', 'lastName' => 'Owner',
                'email' => 'appreview.owner@texasrenters.com', 'mobilePhone' => '(281) 555-0101',
                'attachedLeases' => [], 'attachedPortfolios' => [['id' => self::PORTFOLIO, 'name' => 'Demo Owner Portfolio']],
            ],
            self::TENANT_CONTACT => [
                'id' => (string) self::TENANT_CONTACT, 'type' => 'TENANT', 'firstName' => 'Demo', 'lastName' => 'Tenant',
                'email' => 'appreview.tenant@texasrenters.com', 'mobilePhone' => '(281) 555-0102',
                'attachedLeases' => [['id' => self::LEASE, 'name' => 'Lease', 'role' => 'Primary']], 'attachedPortfolios' => [],
            ],
            default => null,
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    public function portfolio(int $id): ?array
    {
        return $id === self::PORTFOLIO ? [
            'id' => self::PORTFOLIO,
            'name' => 'Demo Owner Portfolio',
            'createdDateTime' => $this->today()->subMonths(8)->startOfMonth()->toIso8601ZuluString(),
            'targetOperatingReserve' => 500.0,
        ] : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function buildings(): array
    {
        $home = fn (int $id, string $street, string $city, ?int $leaseId, string $status, int $beds, float $baths, int $area, int $built, ?string $available): array => [
            'id' => $id, 'name' => $street, 'abbreviation' => strtoupper(str_replace(' ', '', substr($street, 0, 10))),
            'portfolioID' => self::PORTFOLIO, 'leaseID' => $leaseId, 'type' => 'Single Family Home', 'category' => 'Residential',
            'status' => $status, 'numberOfBedrooms' => $beds, 'numberOfBathrooms' => $baths, 'totalArea' => $area, 'areaUnits' => 'Sq Ft',
            'yearBuilt' => $built, 'county' => 'Harris', 'neighborhood' => 'Demo Meadows',
            'address' => ['address' => $street, 'city' => $city, 'stateRegion' => 'TX', 'postalCode' => '77000'],
            'marketing' => ['availableDate' => $available, 'smokingAllowed' => false],
            'propertyManagerList' => [['name' => self::OFFICE, 'roleAsString' => 'Property Manager']],
        ];

        return [
            $home(self::HOME_LEASED, '1234 Demo Meadow Ln', 'Houston', self::LEASE, 'Occupied', 3, 2.0, 1850, 2008, null),
            $home(self::HOME_VACANT, '5678 Sample Oak Dr', 'Katy', null, 'Vacant', 4, 2.5, 2240, 2014, $this->today()->addDays(14)->toDateString()),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function leases(): array
    {
        $end = $this->today()->addDays(self::LEASE_DAYS_LEFT);
        $start = $end->subYear()->addDay();

        return [[
            'id' => self::LEASE,
            'buildingID' => self::HOME_LEASED,
            'portfolioID' => self::PORTFOLIO,
            'status' => 'Active',
            'active' => true,
            'startDate' => $start->toDateString(),
            'endDate' => $end->toDateString(),
            'moveInDate' => $start->toDateString(),
            'moveOutDate' => null,
            'scheduleMoveOutDate' => null,
            'noticeGivenDate' => null,
            'baseRent' => self::RENT,
            'leaseBalance' => self::RENT,
            'lateFeeRule' => '10.00% of rent charges after due day 3',
            'leaseName' => 'Tenant, D.',
            'location' => 'Demo Owner Portfolio | 1234 Demo Meadow Ln',
            'contacts' => [['id' => (string) self::TENANT_CONTACT, 'name' => 'Demo Tenant']],
        ]];
    }

    /**
     * Six months of rent charged on the 1st and paid on the 3rd, with this month's still due.
     *
     * @return array<int, array<string, mixed>>
     */
    public function leaseAdjustments(): array
    {
        $rows = [];

        foreach (range(5, 0) as $monthsAgo) {
            $month = $this->today()->startOfMonth()->subMonths($monthsAgo);
            $rows[] = ['id' => 900400000 + $monthsAgo * 2, 'leaseID' => self::LEASE, 'date' => $month->toDateString(), 'amount' => self::RENT, 'glAccountID' => self::GL_RENT, 'comments' => 'Rent'];

            if ($monthsAgo > 0) {
                $rows[] = [
                    'id' => 900400001 + $monthsAgo * 2, 'leaseID' => self::LEASE, 'date' => $month->addDays(2)->toDateString(),
                    'amount' => -self::RENT, 'glAccountID' => self::GL_RECEIVABLE, 'refNo' => 'EP-'.(4100 + $monthsAgo), 'comments' => 'ePayment received',
                ];
            }
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function glAccounts(): array
    {
        return [
            ['id' => self::GL_RENT, 'name' => 'Rent', 'accountType' => 'Income'],
            ['id' => self::GL_FEES, 'name' => 'Management Fees', 'accountType' => 'Expense'],
            ['id' => self::GL_REPAIRS, 'name' => 'Repairs & Maintenance', 'accountType' => 'Expense'],
            ['id' => self::GL_RECEIVABLE, 'name' => 'Accounts Receivable', 'accountType' => 'A/R'],
            ['id' => 3000, 'name' => 'Owner Draw', 'accountType' => 'Equity'],
            ['id' => self::GL_BANK, 'name' => 'Operating Bank', 'accountType' => 'Bank'],
        ];
    }

    /**
     * The portfolio's general ledger: rent in on the 3rd, the management fee
     * out on the 10th, and one repair two months ago. Only dates up to today.
     *
     * @return array<int, array<string, mixed>>
     */
    public function generalLedger(): array
    {
        $entry = fn (int $account, float $credit, float $debit, string $comment, ?int $building = self::HOME_LEASED): array => [
            'portfolioID' => self::PORTFOLIO, 'buildingID' => $building, 'glaccountID' => $account, 'credit' => $credit, 'debit' => $debit, 'comment' => $comment,
        ];
        $transaction = fn (int $id, CarbonImmutable $date, string $type, string $comment, array $entries): array => [
            'id' => $id, 'txDate' => $date->toDateString(), 'postDate' => $date->toDateString(), 'transactionType' => $type, 'comment' => $comment, 'glentries' => $entries,
        ];

        $rows = [];

        foreach (range(6, 0) as $monthsAgo) {
            $month = $this->today()->startOfMonth()->subMonths($monthsAgo);
            $rent = $month->addDays(2);
            $fee = $month->addDays(9);

            if ($rent->lessThanOrEqualTo($this->today())) {
                $rows[] = $transaction(900200000 + $monthsAgo * 10, $rent, 'Payment', 'Rent', [
                    $entry(self::GL_RENT, self::RENT, 0.0, 'Rent'),
                    $entry(self::GL_BANK, 0.0, self::RENT, 'Rent'),
                ]);
            }

            if ($fee->lessThanOrEqualTo($this->today())) {
                $rows[] = $transaction(900200001 + $monthsAgo * 10, $fee, 'Bill Payment', 'Management fee', [
                    $entry(self::GL_FEES, 0.0, self::MANAGEMENT_FEE, 'Management fee'),
                    $entry(self::GL_BANK, self::MANAGEMENT_FEE, 0.0, 'Management fee'),
                ]);
            }
        }

        $repair = $this->today()->startOfMonth()->subMonths(2)->addDays(14);
        $rows[] = $transaction(900200099, $repair, 'Bill Payment', 'Replaced kitchen faucet', [
            $entry(self::GL_REPAIRS, 0.0, 325.0, 'Replaced kitchen faucet'),
            $entry(self::GL_BANK, 325.0, 0.0, 'Replaced kitchen faucet'),
        ]);

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function bills(): array
    {
        $repair = $this->today()->startOfMonth()->subMonths(2)->addDays(14);
        $lawn = $this->today()->subDays(5);

        return [
            [
                'id' => 900000081, 'vendorID' => self::VENDOR, 'billNumber' => 70081, 'billDate' => $repair->toDateString(),
                'dueDate' => $repair->addDays(15)->toDateString(), 'paymentDate' => $repair->addDays(5)->toDateString(),
                'comments' => 'Replaced kitchen faucet', 'workOrderID' => (string) self::WORK_ORDER_DONE, 'amount' => 325.0,
                'lastModifiedDateTime' => $repair->addDays(5)->toIso8601ZuluString(),
                'billSplits' => [['portfolioID' => self::PORTFOLIO, 'buildingID' => self::HOME_LEASED, 'buildingAbbr' => '1234DEMO', 'glAccountID' => self::GL_REPAIRS, 'amount' => 325.0, 'amountPaid' => 325.0]],
            ],
            [
                'id' => 900000082, 'vendorID' => self::VENDOR, 'billNumber' => 70082, 'billDate' => $lawn->toDateString(),
                'dueDate' => $lawn->addDays(15)->toDateString(), 'comments' => 'Lawn care while vacant', 'amount' => 95.0,
                'lastModifiedDateTime' => $lawn->toIso8601ZuluString(),
                'billSplits' => [['portfolioID' => self::PORTFOLIO, 'buildingID' => self::HOME_VACANT, 'buildingAbbr' => '5678SAMPL', 'glAccountID' => self::GL_REPAIRS, 'amount' => 95.0, 'amountPaid' => 0.0]],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function vendors(): array
    {
        return [['id' => self::VENDOR, 'name' => 'Demo Home Services']];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function workOrders(): array
    {
        $order = fn (int $id, int $building, string $description, string $category, array $extra): array => [
            'id' => (string) $id, 'number' => $id - 900000000 + 9000, 'description' => $description, 'category' => $category,
            'status' => 'Open', 'priority' => 'MEDIUM', 'type' => 'Service Request', 'source' => 'Portal',
            'buildingID' => $building, 'portfolioID' => self::PORTFOLIO, 'unitID' => null,
            'publishToOwnerPortal' => true, 'publishToTenantPortal' => $building === self::HOME_LEASED,
            'approved' => true, 'approvedDate' => null, 'completedDate' => null, 'ownerCost' => null, 'costEstimate' => null,
            'requestedBy' => null, 'assignedVendors' => [['id' => self::VENDOR, 'name' => 'Demo Home Services']],
            ...$extra,
        ];
        $at = fn (int $daysAgo): string => $this->today()->subDays($daysAgo)->setTime(10, 0)->toIso8601ZuluString();

        return [
            $order(self::WORK_ORDER_DONE, self::HOME_LEASED, 'Kitchen faucet is leaking', 'Plumbing', [
                'status' => 'Closed', 'createdDateTime' => $at(62), 'lastModifiedDateTime' => $at(45), 'completedDate' => $at(45),
                'approvedDate' => $this->today()->subDays(61)->toDateString(), 'ownerCost' => 325.0, 'requestedBy' => self::TENANT_CONTACT,
            ]),
            $order(self::WORK_ORDER_AC, self::HOME_LEASED, 'AC not cooling upstairs', 'HVAC', [
                'createdDateTime' => $at(6), 'lastModifiedDateTime' => $at(2), 'approvedDate' => $this->today()->subDays(5)->toDateString(),
                'costEstimate' => 240.0, 'requestedBy' => self::TENANT_CONTACT,
            ]),
            $order(self::WORK_ORDER_APPROVAL, self::HOME_VACANT, 'Make-ready: interior paint touch-up and carpet cleaning before listing', 'General', [
                'approved' => false, 'createdDateTime' => $at(3), 'lastModifiedDateTime' => $at(1), 'costEstimate' => 1150.0, 'assignedVendors' => [],
            ]),
            $order(self::WORK_ORDER_GARAGE, self::HOME_LEASED, 'Garage door remote stopped working', 'General', [
                'createdDateTime' => $at(1), 'lastModifiedDateTime' => $at(1), 'requestedBy' => self::TENANT_CONTACT, 'assignedVendors' => [],
            ]),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function documents(): array
    {
        $document = fn (int $id, string $fileName, string $entityType, int $entityId, bool $owner, bool $tenant, int $daysAgo): array => [
            'id' => $id, 'fileName' => $fileName, 'fileType' => 'application/pdf', 'description' => null,
            'entityType' => $entityType, 'entityId' => $entityId,
            'publishToOwnerPortal' => $owner, 'publishToTenantPortal' => $tenant,
            'createdDateTime' => $this->today()->subDays($daysAgo)->setTime(9, 0)->toIso8601ZuluString(),
        ];
        $statementMonth = $this->today()->startOfMonth()->subMonth()->format('F Y');

        return [
            $document(self::DOC_LEASE, 'Lease Agreement.pdf', 'LEASE', self::LEASE, true, true, 290),
            $document(self::DOC_STATEMENT, "Owner Statement - {$statementMonth}.pdf", 'PORTFOLIO', self::PORTFOLIO, true, false, 3),
            $document(self::DOC_INSPECTION, 'Move-out Inspection Report.pdf', 'BUILDING', self::HOME_VACANT, true, false, 20),
            $document(self::DOC_INVOICE, 'Invoice - Kitchen Faucet.pdf', 'WORK_ORDER', self::WORK_ORDER_DONE, true, false, 45),
            $document(self::DOC_WELCOME, 'Welcome Letter.pdf', 'CONTACT', self::TENANT_CONTACT, false, true, 290),
        ];
    }

    /**
     * Propertyware conversations, as `contacts/{id}/conversations` and `buildings/{id}/conversations` return them.
     *
     * @return array<int, array<string, mixed>>
     */
    public function conversations(): array
    {
        $conversation = fn (string $id, string $type, string $entityType, int $entityId, string $subject, string $text, int $daysAgo): array => [
            'id' => $id, 'type' => $type, 'subject' => $subject, 'entityType' => $entityType, 'entityID' => $entityId,
            'createdDateTime' => $this->today()->subDays($daysAgo)->setTime(15, 0)->toIso8601ZuluString(),
            'comments' => [['id' => $id.'-1', 'text' => $text, 'createdBy' => self::OFFICE, 'createdDateTime' => $this->today()->subDays($daysAgo)->setTime(15, 0)->toIso8601ZuluString()]],
        ];

        return [
            $conversation('900000091', 'OWNER', 'Contact', self::OWNER_CONTACT, 'Welcome to TexasRenters', 'Welcome aboard! Both of your homes are set up in the portal.', 200),
            $conversation('900000092', 'TENANT', 'Contact', self::TENANT_CONTACT, 'Welcome home', 'Welcome to your new home! Reach us here any time.', 290),
            $conversation('900000093', 'OWNER', 'Building', self::HOME_VACANT, 'Listing the home', 'The make-ready estimate is in the app for your approval. We will list the home as soon as it is done.', 2),
            $conversation('900000094', 'TENANT', 'Building', self::HOME_LEASED, 'Annual HVAC service', 'Our technician will service the AC this month. We will text before the visit.', 30),
        ];
    }

    /**
     * Chat hub threads, as `threads` returns them, before their last message is added.
     *
     * @return array<int, array<string, mixed>>
     */
    public function chatThreads(): array
    {
        return [
            [
                'id' => self::OWNER_THREAD, 'status' => 'open', 'subject' => 'Question about my statement', 'type' => 'accounting',
                'contact_id' => (string) self::OWNER_CONTACT, 'phone' => null, 'work_order_id' => null, 'work_order_number' => null,
                'assigned_to' => self::OFFICE, 'assigned_to_photo_url' => null,
            ],
            [
                'id' => self::TENANT_THREAD, 'status' => 'open', 'subject' => 'Garage door remote', 'type' => 'maintenance',
                'contact_id' => (string) self::TENANT_CONTACT, 'phone' => null, 'work_order_id' => (string) self::WORK_ORDER_GARAGE,
                'work_order_number' => (string) (self::WORK_ORDER_GARAGE - 900000000 + 9000), 'assigned_to' => self::OFFICE, 'assigned_to_photo_url' => null,
            ],
        ];
    }

    /**
     * Chat hub messages, each with the thread it belongs to.
     *
     * @return array<int, array<string, mixed>>
     */
    public function chatMessages(): array
    {
        $message = fn (int $id, int $thread, bool $fromClient, string $body, int $hoursAgo): array => [
            'id' => $id, 'thread_id' => $thread, 'direction' => $fromClient ? 'inbound' : 'outbound', 'sender' => $fromClient ? 'user' : 'agent',
            'sender_name' => $fromClient ? null : self::OFFICE, 'sender_photo_url' => null, 'body' => $body, 'media_urls' => [], 'sender_number' => null,
            'created_at' => $this->today()->setTime(9, 0)->subHours($hoursAgo)->toIso8601String(),
        ];

        return [
            $message(900000101, self::OWNER_THREAD, true, 'Hi, what was the $325 charge two months ago?', 50),
            $message(900000102, self::OWNER_THREAD, false, 'That was the kitchen faucet repair at 1234 Demo Meadow Ln. The invoice is under Documents.', 48),
            $message(900000103, self::TENANT_THREAD, true, 'The garage door remote stopped working yesterday.', 20),
            $message(900000104, self::TENANT_THREAD, false, 'Thanks! We opened a work order and a technician will reach out to schedule.', 18),
        ];
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::today('America/Chicago');
    }
}
