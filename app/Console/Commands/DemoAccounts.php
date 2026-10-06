<?php

namespace App\Console\Commands;

use App\Enums\ClientRole;
use App\Models\User;
use App\Services\Demo\DemoData;
use App\Services\Demo\DemoReset;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;

#[Signature('demo:accounts {--owner-password= : Password for the demo owner} {--tenant-password= : Password for the demo tenant}')]
#[Description('Create or refresh the App Review demo owner and tenant logins')]
class DemoAccounts extends Command
{
    /**
     * @var array<string, array{name: string, role: ClientRole, contact: int}>
     */
    private const LOGINS = [
        'appreview.owner@texasrenters.com' => ['name' => 'Demo Owner', 'role' => ClientRole::Owner, 'contact' => DemoData::OWNER_CONTACT],
        'appreview.tenant@texasrenters.com' => ['name' => 'Demo Tenant', 'role' => ClientRole::Resident, 'contact' => DemoData::TENANT_CONTACT],
    ];

    public function handle(DemoReset $reset): int
    {
        $passwords = [
            'appreview.owner@texasrenters.com' => $this->option('owner-password') ?: password('Password for the demo owner'),
            'appreview.tenant@texasrenters.com' => $this->option('tenant-password') ?: password('Password for the demo tenant'),
        ];

        foreach ($passwords as $email => $plain) {
            $validator = Validator::make(['password' => $plain], ['password' => ['required', Password::defaults()]]);

            if ($validator->fails()) {
                $this->components->error("{$email}: ".$validator->errors()->first('password'));

                return self::FAILURE;
            }

            $existing = User::query()->where('email', $email)->first();

            if ($existing !== null && ! $existing->is_demo) {
                $this->components->error("{$email} already belongs to a real login; it was left alone.");

                return self::FAILURE;
            }
        }

        DB::transaction(function () use ($passwords): void {
            foreach (self::LOGINS as $email => $login) {
                $user = User::query()->firstOrNew(['email' => $email]);
                // The model's password cast hashes it, so pass the plain text.
                $user->forceFill([
                    'name' => $login['name'],
                    'password' => $passwords[$email],
                    'email_verified_at' => $user->email_verified_at ?? now(),
                    'is_demo' => true,
                ])->save();

                $user->clientAccounts()->delete();
                $user->clientAccounts()->create(['role' => $login['role'], 'propertyware_id' => (string) $login['contact']]);
            }
        });

        $reset->handle();

        $this->components->info('The demo owner and tenant are ready. Put the logins in App Store Connect › App Review Information.');

        return self::SUCCESS;
    }
}
