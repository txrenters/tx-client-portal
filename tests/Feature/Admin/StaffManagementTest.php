<?php

use App\Enums\StaffRole;
use App\Models\AdminAction;
use App\Models\User;
use App\Notifications\StaffAccessGrantedNotification;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config(['services.admin_emails' => ['it@texasrenters.com']]);
    $this->owner = User::factory()->create(['email' => 'it@texasrenters.com']);
    Notification::fake();
});

/** Staff pages sit behind a fresh password confirmation. */
function confirmed(): array
{
    return ['auth.password_confirmed_at' => time()];
}

test('an owner adds a new staff member, who is emailed a link to choose a password', function () {
    $this->actingAs($this->owner)->withSession(confirmed())
        ->post(route('admin.staff.store'), ['email' => 'Maria@TexasRenters.com', 'name' => 'Maria', 'role' => 'staff'])
        ->assertRedirect();

    $maria = User::query()->where('email', 'maria@texasrenters.com')->sole();
    expect($maria)
        ->staff_role->toBe(StaffRole::Staff)
        ->email_verified_at->not->toBeNull()
        ->and(AdminAction::sole()->action)->toBe('staff_granted');

    Notification::assertSentTo($maria, StaffAccessGrantedNotification::class, fn ($notification) => $notification->passwordToken !== null);
});

test('an existing login gains access without its password being touched', function () {
    $existing = User::factory()->create(['email' => 'lee@texasrenters.com']);
    $password = $existing->password;

    $this->actingAs($this->owner)->withSession(confirmed())
        ->post(route('admin.staff.store'), ['email' => 'lee@texasrenters.com', 'name' => 'Lee', 'role' => 'admin'])
        ->assertRedirect();

    expect($existing->fresh())->staff_role->toBe(StaffRole::Admin)->password->toBe($password);
    Notification::assertSentTo($existing, StaffAccessGrantedNotification::class, fn ($notification) => $notification->passwordToken === null);
});

test('staff use the admin area but cannot manage staff; admins can', function () {
    $staff = User::factory()->create(['staff_role' => StaffRole::Staff]);
    $admin = User::factory()->create(['staff_role' => StaffRole::Admin]);

    $this->actingAs($staff)->get(route('admin.users.index'))->assertOk();
    $this->actingAs($staff)->withSession(confirmed())->get(route('admin.staff.index'))->assertForbidden();

    $this->actingAs($admin)->withSession(confirmed())->get(route('admin.staff.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/Staff')
            ->where('staff.0.email', 'it@texasrenters.com')
            ->where('staff.0.fixed', true));
});

test('the staff page asks for the password again first', function () {
    $this->actingAs($this->owner)->get(route('admin.staff.index'))->assertRedirect(route('password.confirm'));
});

test('removing access keeps the login but closes the admin area', function () {
    $staff = User::factory()->create(['staff_role' => StaffRole::Staff]);

    $this->actingAs($this->owner)->withSession(confirmed())
        ->delete(route('admin.staff.destroy', $staff))
        ->assertRedirect();

    expect($staff->fresh())->not->toBeNull()->staff_role->toBeNull();
    $this->actingAs($staff->fresh())->get(route('admin.dashboard'))->assertForbidden();
});

test('an admin cannot change their own access or an owner\'s', function () {
    $admin = User::factory()->create(['staff_role' => StaffRole::Admin]);

    $this->actingAs($admin)->withSession(confirmed())
        ->patch(route('admin.staff.update', $admin), ['role' => 'staff'])
        ->assertSessionHasErrors('staff');
    $this->actingAs($admin)->withSession(confirmed())
        ->delete(route('admin.staff.destroy', $this->owner))
        ->assertSessionHasErrors(['staff' => 'Owners are set in ADMIN_EMAILS and cannot be changed here.']);

    expect($admin->fresh()->staff_role)->toBe(StaffRole::Admin);
});

test('a suspended staff member loses the admin area', function () {
    $staff = User::factory()->create(['staff_role' => StaffRole::Admin, 'suspended_at' => now()]);

    expect($staff->can('admin'))->toBeFalse()->and($staff->can('manage-staff'))->toBeFalse();
});

test('staff cannot be signed in as', function () {
    $staff = User::factory()->create(['staff_role' => StaffRole::Staff]);

    $this->actingAs($this->owner)->post(route('admin.users.impersonate', $staff))->assertRedirect();

    $this->assertAuthenticatedAs($this->owner);
});

test('the admin area lives at /admin/dashboard, and /admin still gets there', function () {
    expect(route('admin.dashboard', absolute: false))->toBe('/admin/dashboard');

    $this->actingAs($this->owner)->get('/admin')->assertRedirect('/admin/dashboard');
});
