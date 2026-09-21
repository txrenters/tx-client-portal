<?php

namespace App\Actions\Client;

use App\Models\ClientAccount;
use App\Models\User;
use App\Services\Propertyware\Data\Contact;
use App\Services\Propertyware\PropertywareClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SyncClientAccounts
{
    public function __construct(private PropertywareClient $propertyware) {}

    public function handle(User $user): void
    {
        if ($user->email_verified_at === null || ! $this->propertyware->isConfigured()) {
            return;
        }

        $email = Str::lower(trim($user->email));
        $records = $this->propertyware->listAll('contacts', ['email' => $email]);

        DB::transaction(function () use ($user, $email, $records): void {
            $user->newQuery()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            foreach ($records as $record) {
                $contact = Contact::fromArray($record);

                if ($contact->id === '' || $contact->role === null || Str::lower(trim($contact->email ?? '')) !== $email) {
                    continue;
                }

                $account = ClientAccount::query()->where('role', $contact->role)->where('propertyware_id', $contact->id)->first();

                if ($account !== null && $account->user_id !== $user->id) {
                    continue;
                }

                $label = $contact->fullName() ?: 'Account '.$contact->id;

                if ($account === null) {
                    $user->clientAccounts()->firstOrCreate(
                        ['role' => $contact->role, 'propertyware_id' => $contact->id],
                        ['label' => $label],
                    );
                } elseif (blank($account->label)) {
                    $account->update(['label' => $label]);
                }
            }
        });

        $user->unsetRelation('clientAccounts');
    }
}
