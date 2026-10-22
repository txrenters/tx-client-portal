<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\User;
use App\Services\Demo\DemoLogins;
use App\Services\Demo\DemoReset;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;

#[Signature('demo:accounts {--owner-password= : Password for the demo owner} {--tenant-password= : Password for the demo tenant}')]
#[Description('Create or refresh the App Review demo owner and tenant logins')]
class DemoAccounts extends Command
{
    public function handle(DemoReset $reset): int
    {
        $passwords = [
            'owner' => $this->input()->string('owner-password')->toString() ?: password('Password for the demo owner'),
            'tenant' => $this->input()->string('tenant-password')->toString() ?: password('Password for the demo tenant'),
        ];

        foreach ($passwords as $key => $plain) {
            $email = DemoLogins::ALL[$key]['email'];

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

        // The reset keeps the hashes so it can bring a deleted login back, then creates or refreshes both.
        foreach ($passwords as $key => $plain) {
            Setting::put(DemoLogins::hashKey($key), Hash::make($plain));
        }

        $reset->handle();

        $this->components->info('The demo owner and tenant are ready. Put the logins in App Store Connect › App Review Information.');

        return self::SUCCESS;
    }
}
