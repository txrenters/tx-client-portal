<?php

namespace App\Console\Commands;

use App\Actions\Client\InviteClient as InviteClientAction;
use App\Enums\ClientRole;
use App\Models\ClientAccount;
use App\Models\User;
use App\Services\Propertyware\Data\Contact;
use App\Services\Propertyware\PropertywareDirectory;
use App\Services\Propertyware\PropertywareException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

#[Signature('client:invite {email : The client\'s email address} {name : The client\'s full name} {role : tenant or owner} {propertyware_id : The Propertyware contact ID} {--skip-verification : Invite without checking the contact in Propertyware}')]
#[Description('Invite a Propertyware tenant or owner to the client portal')]
class InviteClient extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(InviteClientAction $inviteClient, PropertywareDirectory $directory): int
    {
        $validator = Validator::make($this->arguments(), [
            'email' => ['required', 'email', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', 'in:'.collect(ClientRole::cases())->pluck('value')->implode(',')],
            'propertyware_id' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $role = ClientRole::from($this->input()->string('role')->toString());
        $email = $this->input()->string('email')->toString();
        $propertywareId = $this->input()->string('propertyware_id')->toString();

        $isLinkedToAnotherClient = ClientAccount::query()
            ->where('role', $role)
            ->where('propertyware_id', $propertywareId)
            ->exists();

        if ($isLinkedToAnotherClient) {
            $this->components->error("This Propertyware {$role->value} is already linked to a client login.");

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();

        if ($user?->clientAccountsFor($role)->contains('propertyware_id', $propertywareId)) {
            $this->components->error("{$email} is already linked to that Propertyware record.");

            return self::FAILURE;
        }

        if (! $this->options()['skip-verification'] && ! $this->verifyContact($directory, $role)) {
            return self::FAILURE;
        }

        $inviteClient->handle($email, $this->input()->string('name')->toString(), $role, $propertywareId);

        $this->components->info("Invitation sent to {$email}.");

        return self::SUCCESS;
    }

    /**
     * Confirm the Propertyware contact exists and matches the portal being granted.
     */
    private function verifyContact(PropertywareDirectory $directory, ClientRole $role): bool
    {
        $contactId = $this->input()->string('propertyware_id')->toString();

        try {
            $contact = $directory->findContact($contactId);
        } catch (PropertywareException $exception) {
            $this->components->error($exception->getMessage());
            $this->components->warn('Pass --skip-verification to invite without checking Propertyware.');

            return false;
        }

        if (! $contact instanceof Contact) {
            $this->components->error("No Propertyware contact found with ID {$contactId}.");

            return false;
        }

        if ($contact->role !== $role) {
            $this->components->error("Propertyware contact {$contactId} is not a {$role->value}.");

            return false;
        }

        return true;
    }
}
