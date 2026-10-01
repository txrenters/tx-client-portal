<?php

namespace App\Console\Commands;

use App\Services\Propertyware\Data\Contact;
use App\Services\Propertyware\PropertywareClient;
use App\Services\Propertyware\PropertywareException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('client:contacts {email : The email address to look up}')]
#[Description('List every Propertyware contact using an email address, so the right one can be invited')]
class FindClientContacts extends Command
{
    /**
     * Execute the console command.
     *
     * Several contacts routinely share one address: a manager looking after
     * many portfolios, or a couple on one lease. Staff need to see all of them
     * before choosing which record to invite.
     */
    public function handle(PropertywareClient $propertyware): int
    {
        $email = $this->argument('email');

        try {
            $records = $propertyware->list('contacts', ['email' => $email], limit: PropertywareClient::MAX_LIMIT);
        } catch (PropertywareException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($records === []) {
            $this->components->warn("No Propertyware contact uses {$email}.");

            return self::SUCCESS;
        }

        $rows = collect($records)->map(function (array $record): array {
            $contact = Contact::fromArray($record);

            return [
                $contact->id,
                $contact->role->value ?? '-',
                $contact->fullName(),
                $record['company'] ?: '-',
                count($contact->portfolioIds),
                count($contact->leaseIds),
            ];
        });

        $this->table(
            ['Contact ID', 'Portal', 'Name', 'Company', 'Portfolios', 'Leases'],
            $rows->all(),
        );

        $this->components->info(
            $rows->count().' contact(s) use this address. Invite one with: client:invite '.$email.' "Name" owner|resident <contact id>',
        );

        return self::SUCCESS;
    }
}
