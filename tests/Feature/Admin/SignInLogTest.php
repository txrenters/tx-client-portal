<?php

use App\Models\PortalVisit;
use App\Models\SignInEvent;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config(['services.admin_emails' => ['staff@texasrenters.com']]);
    $this->admin = User::factory()->create(['email' => 'staff@texasrenters.com']);
    $this->client = User::factory()->create(['email' => 'dana@example.com']);
});

test('a web sign-in is logged with where it came from', function () {
    $this->withHeader('User-Agent', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/128.0 Safari/537.36')
        ->post(route('login.store'), ['email' => 'dana@example.com', 'password' => 'password']);

    expect(SignInEvent::sole())
        ->event->toBe('signed_in')
        ->user_id->toBe($this->client->id)
        ->channel->toBe('web')
        ->method->toBe('password')
        ->ip_address->toBe('127.0.0.1');
});

test('a wrong password is logged against the address tried, and never the password', function () {
    $this->post(route('login.store'), ['email' => 'Dana@Example.com', 'password' => 'not-it']);

    $event = SignInEvent::sole();
    expect($event)->event->toBe('failed')->email->toBe('dana@example.com')
        ->and(json_encode($event->toArray()))->not->toContain('not-it');
});

test('app sign-ins and failures are logged too', function () {
    $this->postJson(route('api.v1.auth.login'), ['email' => 'dana@example.com', 'password' => 'wrong', 'device_name' => 'iphone']);
    $this->postJson(route('api.v1.auth.login'), ['email' => 'dana@example.com', 'password' => 'password', 'device_name' => 'iphone']);

    expect(SignInEvent::query()->orderBy('id')->pluck('event')->all())->toBe(['failed', 'signed_in'])
        ->and(SignInEvent::query()->pluck('channel')->unique()->all())->toBe(['app']);
});

test('a suspended login trying to sign in is logged as blocked', function () {
    $this->client->forceFill(['suspended_at' => now()])->save();

    $this->post(route('login.store'), ['email' => 'dana@example.com', 'password' => 'password']);

    expect(SignInEvent::sole()->event)->toBe('blocked');
});

test('signing in as a client is not logged as the client signing in', function () {
    $this->actingAs($this->admin)->post(route('admin.users.impersonate', $this->client));
    $this->delete(route('impersonate.stop'));

    expect(SignInEvent::count())->toBe(0);
});

test('coming back after a break counts as a visit, but carrying on does not', function () {
    $this->actingAs($this->client)->get(route('help'));
    $this->actingAs($this->client->fresh())->get(route('help'));
    $this->travel(45)->minutes();
    $this->actingAs($this->client->fresh())->get(route('help'));

    expect(PortalVisit::query()->where('user_id', $this->client->id)->count())->toBe(2);
});

test('insights show visits, sign-in counts, repeated failures and the log', function () {
    PortalVisit::create(['user_id' => $this->client->id, 'channel' => 'web']);
    PortalVisit::create(['user_id' => $this->client->id, 'channel' => 'app']);
    SignInEvent::create(['user_id' => $this->client->id, 'email' => 'dana@example.com', 'event' => 'signed_in', 'channel' => 'web', 'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Safari/604.1']);
    foreach (range(1, 3) as $attempt) {
        SignInEvent::create(['email' => 'guess@example.com', 'event' => 'failed', 'channel' => 'web', 'ip_address' => '10.0.0.'.$attempt]);
    }

    $this->actingAs($this->admin)->get(route('admin.insights'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('visits.total', 2)
            ->where('visits.visitors', 1)
            ->where('visits.app', 1)
            ->has('visits.daily', 30)
            ->where('signIns.successful', 1)
            ->where('signIns.failed', 3)
            ->where('signIns.repeated.0.email', 'guess@example.com')
            ->where('signIns.repeated.0.addresses', 3)
            ->where('signIns.repeated.0.userId', null)
            ->where('signInLog.total', 4));

    $this->actingAs($this->admin)->get(route('admin.insights', ['log' => 'signed_in']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('signInLog.total', 1)
            ->where('signInLog.data.0.user.name', $this->client->name)
            ->where('signInLog.data.0.device', 'Safari on iPhone'));

    $this->actingAs($this->admin)->get(route('admin.insights', ['email' => 'guess@example.com']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('signInLog.total', 3));
});

test('the log is pruned after its keeping period', function () {
    SignInEvent::create(['email' => 'old@example.com', 'event' => 'failed', 'channel' => 'web']);
    SignInEvent::query()->update(['created_at' => now()->subDays(SignInEvent::KEEP_DAYS + 1)]);

    $this->artisan('model:prune', ['--model' => [SignInEvent::class]]);

    expect(SignInEvent::count())->toBe(0);
});
