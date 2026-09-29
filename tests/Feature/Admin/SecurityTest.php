<?php

use App\Enums\StaffRole;
use App\Models\AdminAction;
use App\Models\BlockedIp;
use App\Models\ClientInvitation;
use App\Models\SecurityEvent;
use App\Models\SignInEvent;
use App\Models\User;
use App\Notifications\SecurityAlertNotification;
use App\Services\Security\IpGuard;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;

/** A public address, since private ones are never blocked automatically. */
const ATTACKER = '203.0.113.9';

beforeEach(function () {
    config(['services.admin_emails' => ['staff@texasrenters.com']]);
    $this->admin = User::factory()->create(['email' => 'staff@texasrenters.com']);
    Notification::fake();
});

/**
 * Failed web sign-ins from one address against the given emails.
 *
 * @param  array<int, string>  $emails
 */
function failSignInsFrom(string $ip, array $emails): void
{
    foreach ($emails as $email) {
        test()->withServerVariables(['REMOTE_ADDR' => $ip])
            ->post(route('login.store'), ['email' => $email, 'password' => 'guess']);
    }
}

test('a blocked address is refused on the web and in the app', function () {
    BlockedIp::create(['ip_address' => ATTACKER, 'reason' => 'Testing', 'source' => 'manual']);

    $this->withServerVariables(['REMOTE_ADDR' => ATTACKER])->get(route('login'))->assertForbidden();
    $this->withServerVariables(['REMOTE_ADDR' => ATTACKER])->getJson(route('api.v1.me'))
        ->assertForbidden()
        ->assertJsonPath('message', 'Access denied.');

    expect(BlockedIp::sole()->hits)->toBe(2)
        ->and(SecurityEvent::query()->where('type', 'blocked_request')->count())->toBe(1);
});

test('an expired block lets the address back in', function () {
    BlockedIp::create(['ip_address' => ATTACKER, 'reason' => 'Testing', 'source' => 'automatic', 'expires_at' => now()->subMinute()]);

    $this->withServerVariables(['REMOTE_ADDR' => ATTACKER])->get(route('login'))->assertOk();
});

test('credential stuffing from one address blocks it for a day and emails the admins', function () {
    $emails = collect(range(1, IpGuard::STUFFING_FAILURES))->map(fn (int $n): string => 'person'.($n % IpGuard::STUFFING_EMAILS).'@example.com')->all();

    failSignInsFrom(ATTACKER, $emails);

    $block = BlockedIp::sole();
    expect($block)
        ->ip_address->toBe(ATTACKER)
        ->source->toBe('automatic')
        ->and($block->expires_at->between(now()->addHours(23), now()->addHours(25)))->toBeTrue();
    Notification::assertSentOnDemand(SecurityAlertNotification::class, fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === ['staff@texasrenters.com']);

    $this->withServerVariables(['REMOTE_ADDR' => ATTACKER])->get(route('login'))->assertForbidden();
});

test('a few mistyped passwords do not block anyone', function () {
    User::factory()->create(['email' => 'dana@example.com']);

    failSignInsFrom(ATTACKER, array_fill(0, 5, 'dana@example.com'));

    expect(BlockedIp::count())->toBe(0);
});

test('an address staff sign in from is never blocked automatically', function () {
    SignInEvent::create(['user_id' => $this->admin->id, 'event' => 'signed_in', 'channel' => 'web', 'ip_address' => ATTACKER]);

    failSignInsFrom(ATTACKER, array_map(fn (int $n): string => "person{$n}@example.com", range(1, IpGuard::STUFFING_FAILURES)));

    expect(BlockedIp::count())->toBe(0);
});

test('a private address, such as a proxy, is never blocked automatically', function () {
    failSignInsFrom('10.0.0.5', array_map(fn (int $n): string => "person{$n}@example.com", range(1, IpGuard::STUFFING_FAILURES)));

    expect(BlockedIp::count())->toBe(0);
});

test('scanner paths are logged, answered with a 404, and repeat scanners blocked', function () {
    foreach (['/.env', '/wp-login.php', '/.git/config'] as $path) {
        $this->withServerVariables(['REMOTE_ADDR' => ATTACKER])->get($path)->assertNotFound();
    }

    expect(SecurityEvent::query()->where('type', 'probe')->count())->toBe(3)
        ->and(BlockedIp::query()->where('ip_address', ATTACKER)->exists())->toBeTrue();
});

test('a client trying an admin page is logged', function () {
    $client = User::factory()->create();

    $this->actingAs($client)->get(route('admin.users.index'))->assertForbidden();

    expect(SecurityEvent::sole())->type->toBe('admin_denied')->user_id->toBe($client->id);
});

test('an invalid invitation link and a bad webhook signature are logged', function () {
    ClientInvitation::factory()->create();

    $this->get(route('invitations.show', 'not-a-real-token'))->assertOk();
    $this->postJson(route('api.v1.hub.events'), ['event' => 'message.sent'])->assertUnauthorized();

    expect(SecurityEvent::query()->pluck('type')->sort()->values()->all())->toBe(['bad_webhook_signature', 'invalid_invitation']);
});

test('staff block and unblock an address by hand, and both are audited', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.security.blocks.store'), ['ip' => ATTACKER, 'reason' => 'Spam', 'hours' => 24])
        ->assertRedirect();

    $block = BlockedIp::sole();
    expect($block)->source->toBe('manual')->blocked_by->toBe($this->admin->id);

    $this->actingAs($this->admin)->delete(route('admin.security.blocks.destroy', $block))->assertRedirect();

    expect(BlockedIp::count())->toBe(0)
        ->and(AdminAction::query()->pluck('action')->all())->toBe(['ip_blocked', 'ip_unblocked']);
});

test('staff cannot block the address they are using', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.security.blocks.store'), ['ip' => '127.0.0.1', 'reason' => 'Oops'])
        ->assertSessionHasErrors('ip');

    expect(BlockedIp::count())->toBe(0);
});

test('the security page lists suspicious addresses and the event log', function () {
    foreach (range(1, 3) as $n) {
        SignInEvent::create(['email' => "p{$n}@example.com", 'event' => 'failed', 'channel' => 'web', 'ip_address' => ATTACKER]);
    }
    SecurityEvent::create(['type' => 'probe', 'ip_address' => ATTACKER, 'path' => '/.env']);

    $this->actingAs($this->admin)->get(route('admin.security'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/Security')
            ->where('suspicious.0.ip', ATTACKER)
            ->where('suspicious.0.failures', 3)
            ->where('suspicious.0.emails', 3)
            ->where('suspicious.0.probes', 1)
            ->where('events.total', 1));
});

test('staff without admin rights can still use the security page', function () {
    $staff = User::factory()->create(['staff_role' => StaffRole::Staff]);

    $this->actingAs($staff)->get(route('admin.security'))->assertOk();
    $this->actingAs(User::factory()->create())->get(route('admin.security'))->assertForbidden();
});

test('every response carries the browser protections', function () {
    $this->get(route('login'))
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});
