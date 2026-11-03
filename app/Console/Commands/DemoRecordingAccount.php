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

#[Signature('demo:recording-account {--password= : Password for the recording login}')]
#[Description('Create or refresh the demo login that is deleted in the App Review screen recording')]
class DemoRecordingAccount extends Command
{
    public function handle(DemoReset $reset): int
    {
        $email = DemoLogins::ALL['recording']['email'];
        $plain = $this->input()->string('password')->toString() ?: password("Password for {$email}");

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

        // Only this login's hash is set; the owner and tenant keep theirs.
        Setting::put(DemoLogins::hashKey('recording'), Hash::make($plain));

        $reset->handle();

        $this->components->info("{$email} is ready. Delete it in the app on camera; tonight's demo:reset brings it back.");

        return self::SUCCESS;
    }
}
