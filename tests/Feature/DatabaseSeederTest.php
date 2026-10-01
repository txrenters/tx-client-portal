<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;

test('the development seeder refuses to run in production', function () {
    app()->detectEnvironment(fn (): string => 'production');

    // Run directly: through artisan, db:seed would first ask for confirmation.
    expect(fn () => app(DatabaseSeeder::class)->run())
        ->toThrow(RuntimeException::class);

    expect(User::query()->where('email', 'staff@example.com')->exists())->toBeFalse();
});

test('the development seeder creates the local clients and a staff admin', function () {
    $this->seed(DatabaseSeeder::class);

    expect(User::query()->whereIn('email', ['resident@example.com', 'owner@example.com', 'both@example.com'])->count())->toBe(3)
        ->and(User::query()->where('email', 'staff@example.com')->first()?->isStaff())->toBeTrue();
});
