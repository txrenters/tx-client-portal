<?php

use App\Enums\ClientRole;
use App\Models\AdminAction;
use App\Models\ClientAccount;
use App\Models\ClientInvitation;
use App\Models\User;
use App\Notifications\ClientInvitationNotification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config(['services.admin_emails' => ['staff@texasrenters.com']]);
    $this->admin = User::factory()->create(['email' => 'staff@texasrenters.com']);
    Notification::fake();
});

function invitationDetails(array $overrides = []): array
{
    return ['email' => 'Dana@Example.com', 'name' => 'Dana Owner', 'role' => 'owner', 'propertyware_id' => '123456', ...$overrides];
}

test('staff invite a propertyware owner, and who sent it is kept', function () {
    Http::fake(['*' => Http::response(['id' => '123456', 'type' => 'OWNER', 'firstName' => 'Dana', 'lastName' => 'Owner'])]);

    $this->actingAs($this->admin)->post(route('admin.invitations.store'), invitationDetails())
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', 'Invitation sent to dana@example.com.');

    expect(ClientInvitation::sole())
        ->email->toBe('dana@example.com')
        ->role->toBe(ClientRole::Owner)
        ->invited_by->toBe($this->admin->id)
        ->and(AdminAction::sole()->action)->toBe('invitation_sent');
    Notification::assertSentOnDemand(ClientInvitationNotification::class);
});

test('an invitation for the wrong portal is refused', function () {
    Http::fake(['*' => Http::response(['id' => '123456', 'type' => 'TENANT'])]);

    $this->actingAs($this->admin)->post(route('admin.invitations.store'), invitationDetails())
        ->assertSessionHasErrors(['role' => 'That Propertyware contact is not a owner.']);

    expect(ClientInvitation::count())->toBe(0);
});

test('a record already on the portal cannot be invited again', function () {
    ClientAccount::factory()->owner()->create(['propertyware_id' => '123456']);

    $this->actingAs($this->admin)->post(route('admin.invitations.store'), invitationDetails())
        ->assertSessionHasErrors('propertyware_id');

    Http::assertNothingSent();
    expect(ClientInvitation::count())->toBe(0);
});

test('the list narrows by status', function () {
    ClientInvitation::factory()->create(['email' => 'pending@example.com']);
    ClientInvitation::factory()->expired()->create(['email' => 'expired@example.com']);
    ClientInvitation::factory()->create(['email' => 'cancelled@example.com', 'cancelled_at' => now()]);

    $this->actingAs($this->admin)->get(route('admin.invitations.index', ['status' => 'expired']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/Invitations')
            ->where('invitations.total', 1)
            ->where('invitations.data.0.email', 'expired@example.com')
            ->where('counts.pending', 1));
});

test('the invite dialog lists the propertyware records using an email and whether they are on the portal', function () {
    Http::fake(['*' => Http::response([
        ['id' => 1, 'type' => 'OWNER', 'firstName' => 'Dana', 'lastName' => 'Owner', 'email' => 'dana@example.com', 'attachedPortfolios' => [['id' => 7]]],
        ['id' => 2, 'type' => 'TENANT', 'firstName' => 'Dana', 'lastName' => 'Tenant', 'email' => 'dana@example.com'],
        ['id' => 3, 'type' => 'OWNER', 'firstName' => 'Someone', 'lastName' => 'Else', 'email' => 'other@example.com'],
    ])]);
    $linked = User::factory()->create(['email' => 'dana@example.com']);
    ClientAccount::factory()->resident()->for($linked)->create(['propertyware_id' => '2']);

    $this->actingAs($this->admin)
        ->get(route('admin.invitations.index', ['lookup' => 'dana@example.com']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->reloadOnly('contacts', fn (AssertableInertia $reload) => $reload
                ->has('contacts.results', 2)
                ->where('contacts.results.0.portfolios', 1)
                ->where('contacts.results.0.linkedTo', null)
                ->where('contacts.results.1.linkedTo', 'dana@example.com')));
});

test('resending sends a fresh link and retires the old one', function () {
    $old = ClientInvitation::factory()->expired()->create(['email' => 'dana@example.com']);

    $this->actingAs($this->admin)->post(route('admin.invitations.resend', $old))->assertRedirect();

    expect($old->fresh()->cancelled_at)->not->toBeNull()
        ->and(ClientInvitation::query()->pending()->sole())
        ->email->toBe('dana@example.com')
        ->propertyware_id->toBe($old->propertyware_id);
});

test('a cancelled invitation link no longer works', function () {
    $invitation = ClientInvitation::factory()->withToken('secret-token')->create();

    $this->actingAs($this->admin)->delete(route('admin.invitations.destroy', $invitation))->assertRedirect();

    expect(ClientInvitation::findPending('secret-token'))->toBeNull();
});

test('clients cannot see or send invitations', function () {
    $client = User::factory()->create();

    $this->actingAs($client)->get(route('admin.invitations.index'))->assertForbidden();
    $this->actingAs($client)->post(route('admin.invitations.store'), invitationDetails())->assertForbidden();

    expect(ClientInvitation::count())->toBe(0);
});
