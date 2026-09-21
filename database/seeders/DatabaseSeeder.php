<?php

namespace Database\Seeders;

use App\Enums\ClientRole;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database with a client of each portal type for local development.
     */
    public function run(): void
    {
        $this->createClient('resident@example.com', 'Taylor Tenant', [ClientRole::Resident]);
        $this->createClient('owner@example.com', 'Olivia Owner', [ClientRole::Owner]);
        $this->createClient('both@example.com', 'Blake Both', [ClientRole::Resident, ClientRole::Owner]);
    }

    /**
     * @param  array<int, ClientRole>  $roles
     */
    private function createClient(string $email, string $name, array $roles): void
    {
        $user = User::firstOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => 'password'],
        );

        $user->forceFill(['email_verified_at' => now()])->save();

        foreach ($roles as $role) {
            $user->clientAccounts()->firstOrCreate(
                ['role' => $role],
                ['propertyware_id' => (string) fake()->unique()->numberBetween(100000, 999999)],
            );
        }
    }
}
