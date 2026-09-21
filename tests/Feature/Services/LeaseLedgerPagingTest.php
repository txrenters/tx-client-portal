<?php

use App\Services\Propertyware\PropertywareSoap;

/**
 * Propertyware hands back ten ledger entries at a time however large a page we
 * ask for, so reading one page loses every earlier charge and payment.
 */
test('reads the whole ledger, ten entries at a time', function () {
    $pages = [];

    $soap = new class('https://soap.test/api', 'portal', 'secret', $pages) extends PropertywareSoap
    {
        /** @var array<int, array<string, mixed>> */
        public array $asked = [];

        public function __construct(?string $url, ?string $username, ?string $password, public array $pages)
        {
            parent::__construct($url, $username, $password);
        }

        protected function client(): SoapClient
        {
            $recorder = $this;

            return new class($recorder) extends SoapClient
            {
                public function __construct(private object $recorder)
                {
                    parent::__construct(null, ['location' => 'https://soap.test/api', 'uri' => 'urn:test']);
                }

                public function getReadOnlyLeaseLedger(array $criteria): object
                {
                    $this->recorder->asked[] = $criteria;
                    $page = (int) $criteria['pageNumber'];

                    // Two full pages, then a short one: 25 entries in all.
                    $count = match (true) {
                        $page <= 2 => 10,
                        $page === 3 => 5,
                        default => 0,
                    };

                    return (object) [
                        'items' => array_map(
                            fn (int $index): array => ['ID' => ($page * 100) + $index, 'date' => '2026-01-01', 'amount' => 10.0, 'account' => ['name' => 'Rent']],
                            range(1, max($count, 1)),
                        ),
                    ];
                }
            };
        }
    };

    $entries = $soap->leaseLedger(55, '2025-01-01', '2026-01-01');

    expect($entries)->toHaveCount(25)
        ->and(collect($soap->asked)->pluck('pageNumber')->all())->toBe([1, 2, 3])
        ->and($soap->asked[0])->toMatchArray([
            'leaseId' => 55,
            'startDate' => '2025-01-01T00:00:00',
            'endDate' => '2026-01-01T23:59:59',
        ]);
});
