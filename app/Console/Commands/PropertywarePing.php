<?php

namespace App\Console\Commands;

use App\Services\Propertyware\PropertywareClient;
use App\Services\Propertyware\PropertywareException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('propertyware:ping {resource=buildings : The collection to read}')]
#[Description('Check connectivity to the Propertyware API without printing client data')]
class PropertywarePing extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(PropertywareClient $propertyware): int
    {
        if (! $propertyware->isConfigured()) {
            $this->components->error('Propertyware credentials are missing from your environment file.');

            return self::FAILURE;
        }

        $resource = $this->input()->string('resource')->toString();

        try {
            $records = $propertyware->list($resource, limit: 1);
        } catch (PropertywareException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Connected to Propertyware and read [{$resource}].");
        $this->components->twoColumnDetail('Records returned', (string) count($records));

        if ($records !== []) {
            $this->components->twoColumnDetail('Fields available', implode(', ', array_keys($records[0])));
        }

        return self::SUCCESS;
    }
}
