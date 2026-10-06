<?php

use App\Enums\ClientRole;
use App\Models\ClientAccount;
use App\Models\DemoRecord;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkOrderApproval;
use App\Services\Demo\DemoData;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

test('creates both demo logins, verified and linked to the made-up contacts', function () {
    Notification::fake();

    $this->artisan('demo:accounts', ['--owner-password' => 'Owner-pass-2026!', '--tenant-password' => 'Tenant-pass-2026!'])->assertSuccessful();

    $owner = User::where('email', 'appreview.owner@texasrenters.com')->sole();
    $tenant = User::where('email', 'appreview.tenant@texasrenters.com')->sole();

    expect($owner)->is_demo->toBeTrue()->name->toBe('Demo Owner')
        ->and($owner->email_verified_at)->not->toBeNull()
        ->and(Hash::check('Owner-pass-2026!', $owner->password))->toBeTrue()
        ->and($owner->clientAccounts->map(fn ($a) => [$a->role, $a->propertyware_id])->all())->toBe([[ClientRole::Owner, (string) DemoData::OWNER_CONTACT]])
        ->and($tenant->clientAccounts->map(fn ($a) => [$a->role, $a->propertyware_id])->all())->toBe([[ClientRole::Resident, (string) DemoData::TENANT_CONTACT]]);
    // Only the two bell notices are written; no credentials are mailed.
    Notification::assertCount(2);
});

test('running it again resets the passwords and keeps one account each', function () {
    $this->artisan('demo:accounts', ['--owner-password' => 'First-pass-2026!', '--tenant-password' => 'First-pass-2026!'])->assertSuccessful();
    $this->artisan('demo:accounts', ['--owner-password' => 'Second-pass-2026!', '--tenant-password' => 'Second-pass-2026!'])->assertSuccessful();

    $owner = User::where('email', 'appreview.owner@texasrenters.com')->sole();

    expect(Hash::check('Second-pass-2026!', $owner->password))->toBeTrue()
        ->and($owner->clientAccounts)->toHaveCount(1);
});

test('refuses to take over an existing real login with the demo email', function () {
    User::factory()->create(['email' => 'appreview.owner@texasrenters.com']);

    $this->artisan('demo:accounts', ['--owner-password' => 'Owner-pass-2026!', '--tenant-password' => 'Tenant-pass-2026!'])->assertFailed();

    expect(User::where('email', 'appreview.owner@texasrenters.com')->sole()->is_demo)->toBeFalse();
});

test('reset wipes what the reviewer created and starts a fresh demo cache', function () {
    [$user, $account] = demoLogin(ClientRole::Owner);
    DemoRecord::store('workorder', fn (int $id) => ['id' => (string) $id]);
    WorkOrderApproval::factory()->for($user)->for($account)->create();
    $real = WorkOrderApproval::factory()->create();
    $before = Setting::valueOf('demo.cache_generation', '0');

    $this->artisan('demo:reset')->assertSuccessful();

    expect(DemoRecord::count())->toBe(0)
        ->and(WorkOrderApproval::pluck('id')->all())->toBe([$real->id])
        ->and(Setting::valueOf('demo.cache_generation'))->not->toBe($before);
});

test('reset runs every night at 3am Chicago time', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command ?? '', 'demo:reset'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 3 * * *')
        ->and($event->timezone)->toBe('America/Chicago');
});

test('reset brings back a demo login that was deleted, with the same password and link', function () {
    $this->artisan('demo:accounts', ['--owner-password' => 'Owner-pass-2026!', '--tenant-password' => 'Tenant-pass-2026!'])->assertSuccessful();
    User::where('email', 'appreview.tenant@texasrenters.com')->sole()->delete();
    expect(User::where('email', 'appreview.tenant@texasrenters.com')->exists())->toBeFalse();

    $this->artisan('demo:reset')->assertSuccessful();

    $tenant = User::where('email', 'appreview.tenant@texasrenters.com')->sole();
    expect($tenant)->is_demo->toBeTrue()->name->toBe('Demo Tenant')
        ->and($tenant->email_verified_at)->not->toBeNull()
        ->and(Hash::check('Tenant-pass-2026!', $tenant->password))->toBeTrue()
        ->and($tenant->clientAccounts->map(fn ($a) => [$a->role, $a->propertyware_id])->all())->toBe([[ClientRole::Resident, (string) DemoData::TENANT_CONTACT]]);
    expect(User::where('email', 'appreview.owner@texasrenters.com')->count())->toBe(1);
});

test('reset clears two-factor, suspension and renames, and leaves real logins alone', function () {
    $this->artisan('demo:accounts', ['--owner-password' => 'Owner-pass-2026!', '--tenant-password' => 'Tenant-pass-2026!'])->assertSuccessful();
    $owner = User::where('email', 'appreview.owner@texasrenters.com')->sole();
    $owner->forceFill(['name' => 'Apple Reviewer', 'two_factor_secret' => 'secret', 'two_factor_recovery_codes' => 'codes', 'two_factor_confirmed_at' => now(), 'suspended_at' => now()])->save();
    $real = User::factory()->create(['name' => 'Real', 'suspended_at' => now()]);

    $this->artisan('demo:reset')->assertSuccessful();

    expect($owner->fresh())->name->toBe('Demo Owner')->two_factor_secret->toBeNull()->two_factor_recovery_codes->toBeNull()
        ->two_factor_confirmed_at->toBeNull()->suspended_at->toBeNull()
        ->and($real->fresh())->name->toBe('Real')->suspended_at->not->toBeNull();
});

test('accounts clears two-factor and suspension too', function () {
    $this->artisan('demo:accounts', ['--owner-password' => 'Owner-pass-2026!', '--tenant-password' => 'Tenant-pass-2026!'])->assertSuccessful();
    User::where('email', 'appreview.owner@texasrenters.com')->update(['two_factor_secret' => 'secret', 'suspended_at' => now()]);

    $this->artisan('demo:accounts', ['--owner-password' => 'Owner-pass-2026!', '--tenant-password' => 'Tenant-pass-2026!'])->assertSuccessful();

    expect(User::where('email', 'appreview.owner@texasrenters.com')->sole())->two_factor_secret->toBeNull()->suspended_at->toBeNull();
});

test('running accounts again keeps the same app account ids', function () {
    $this->artisan('demo:accounts', ['--owner-password' => 'Owner-pass-2026!', '--tenant-password' => 'Tenant-pass-2026!'])->assertSuccessful();
    $ids = ClientAccount::orderBy('id')->pluck('id')->all();

    $this->artisan('demo:accounts', ['--owner-password' => 'Owner-pass-2026!', '--tenant-password' => 'Tenant-pass-2026!'])->assertSuccessful();

    expect(ClientAccount::orderBy('id')->pluck('id')->all())->toBe($ids)->toHaveCount(2);
});
