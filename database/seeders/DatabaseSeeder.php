<?php

namespace Database\Seeders;

use App\Enums\ClientRole;
use App\Enums\StaffRole;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database with a client of each portal type, and a
     * staff admin for the admin area, for local development.
     *
     * Every account here signs in with the password "password", and one is a
     * staff admin, so the seeder refuses to run in production: a stray
     * `db:seed --force` on the server would otherwise open the admin area.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The development seeder must not run in production: it creates accounts with known passwords.');
        }

        $this->createClient('resident@example.com', 'Taylor Tenant', [ClientRole::Resident]);
        $this->createClient('owner@example.com', 'Olivia Owner', [ClientRole::Owner]);
        $this->createClient('both@example.com', 'Blake Both', [ClientRole::Resident, ClientRole::Owner]);

        User::firstOrCreate(
            ['email' => 'staff@example.com'],
            ['name' => 'Sam Staff', 'password' => 'password'],
        )->forceFill(['email_verified_at' => now(), 'staff_role' => StaffRole::Admin])->save();
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
