<?php

namespace App\Console\Commands;

use App\Services\Demo\DemoReset;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('demo:reset')]
#[Description('Wipe what the App Review logins created and start their demo afresh')]
class DemoResetCommand extends Command
{
    public function handle(DemoReset $reset): int
    {
        $reset->handle();
        $this->components->info('The demo is back to its starting point.');

        return self::SUCCESS;
    }
}
