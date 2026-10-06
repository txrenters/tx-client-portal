<?php

use App\Models\ApprovalPushNotice;
use App\Models\ClientAccount;
use App\Models\PushToken;
use App\Models\User;
use App\Models\WorkOrderApproval;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['services.propertyware.discover_accounts' => false]);
    $this->workOrders = [
        ['id' => '1', 'number' => 40001, 'description' => 'Leaking sink', 'category' => 'Plumbing', 'status' => 'Open',
            'buildingID' => 7, 'portfolioID' => 2, 'publishToOwnerPortal' => true, 'approved' => false,
            'lastModifiedDateTime' => '2026-09-10T10:00:00.000Z'],
        ['id' => '2', 'number' => 40002, 'description' => 'Already approved', 'status' => 'Open',
            'buildingID' => 7, 'portfolioID' => 2, 'publishToOwnerPortal' => true, 'approved' => true, 'approvedDate' => '2026-09-01',
            'lastModifiedDateTime' => '2026-09-10T10:00:00.000Z'],
    ];
    $this->contactFails = [];

    Http::fake(function (Request $request) {
        $url = $request->url();

        if (str_contains($url, 'exp.host')) {
            return Http::response(['data' => [['status' => 'ok', 'id' => 'ticket']]]);
        }

        if (preg_match('#/contacts/(\d+)#', $url, $match)) {
            return in_array($match[1], $this->contactFails, true)
                ? Http::response([], 500)
                : Http::response(['id' => $match[1], 'type' => 'OWNER', 'attachedLeases' => [], 'attachedPortfolios' => [['id' => 2, 'name' => 'Portfolio']]]);
        }

        return match (true) {
            str_contains($url, '/buildings/7') => Http::response(['id' => 7, 'portfolioID' => 2, 'address' => ['address' => '12 Elm St']]),
            str_contains($url, '/buildings') => Http::response([['id' => 7, 'portfolioID' => 2, 'address' => ['address' => '12 Elm St']]]),
            str_contains($url, '/workorders') => Http::response($this->workOrders),
            default => Http::response([]),
        };
    });

    $this->user = User::factory()->create();
    $this->account = ClientAccount::factory()->owner()->for($this->user)->create(['propertyware_id' => '500']);
    PushToken::factory()->for($this->user)->create(['token' => 'ExponentPushToken[owner]']);
});

/** The pushes Expo was asked to deliver, in order. */
function sentPushes(): array
{
    return collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn (Request $request) => str_contains($request->url(), 'exp.host'))
        ->flatMap(fn (Request $request) => $request->data())
        ->values()
        ->all();
}

test('pushes the owner a work order waiting for their approval, once', function () {
    $this->artisan('approvals:notify')->assertSuccessful();
    $this->artisan('approvals:notify')->assertSuccessful();

    $pushes = sentPushes();

    expect($pushes)->toHaveCount(1)
        ->and($pushes[0]['title'])->toBe('Approval needed')
        ->and($pushes[0]['body'])->toBe('#40001 · Plumbing · 12 Elm St')
        ->and($pushes[0]['channelId'])->toBe('approvals')
        ->and($pushes[0]['data'])->toMatchArray(['type' => 'work_order_approval', 'workOrderId' => '1', 'accountId' => $this->account->id]);

    expect(ApprovalPushNotice::sole())
        ->client_account_id->toBe($this->account->id)
        ->work_order_id->toBe('1');
});

test('a work order the owner already approved in the portal is not pushed', function () {
    WorkOrderApproval::factory()->for($this->user)->for($this->account)->create([
        'propertyware_contact_id' => '500', 'work_order_id' => '1', 'decision' => 'approved',
    ]);

    $this->artisan('approvals:notify')->assertSuccessful();

    expect(sentPushes())->toBe([]);
});

test('the first run records what is already waiting without pushing it', function () {
    $this->artisan('approvals:notify', ['--seed' => true])->assertSuccessful();

    expect(sentPushes())->toBe([])
        ->and(ApprovalPushNotice::count())->toBe(1);

    $this->artisan('approvals:notify')->assertSuccessful();
    expect(sentPushes())->toBe([]);
});

test('the demo owner is never looked up in Propertyware, not even when seeding', function (bool $seed) {
    $demo = User::factory()->demo()->create();
    ClientAccount::factory()->owner()->for($demo)->create(['propertyware_id' => '900000001']);
    PushToken::factory()->for($demo)->create(['token' => 'ExponentPushToken[demo]']);

    $this->artisan('approvals:notify', $seed ? ['--seed' => true] : [])->assertSuccessful();

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '900000001'));
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/contacts/500'));

    expect(ApprovalPushNotice::pluck('client_account_id')->all())->toBe([$this->account->id])
        ->and(collect(sentPushes())->pluck('to')->all())->not->toContain('ExponentPushToken[demo]');
})->with([[false], [true]]);

test('an owner who switched approval pushes off is not pushed, and is not flooded on switching back', function () {
    $this->user->forceFill(['notification_preferences' => ['work_order_approvals_push' => false]])->save();

    $this->artisan('approvals:notify')->assertSuccessful();
    expect(sentPushes())->toBe([]);

    $this->user->forceFill(['notification_preferences' => ['work_order_approvals_push' => true]])->save();
    $this->artisan('approvals:notify')->assertSuccessful();
    expect(sentPushes())->toBe([]);
});

test('one owner Propertyware cannot read does not stop the others', function () {
    $other = User::factory()->create();
    ClientAccount::factory()->owner()->for($other)->create(['propertyware_id' => '501']);
    PushToken::factory()->for($other)->create(['token' => 'ExponentPushToken[other]']);
    $this->contactFails = ['500'];

    $this->artisan('approvals:notify')->assertSuccessful();

    expect(collect(sentPushes())->pluck('to')->all())->toBe(['ExponentPushToken[other]'])
        ->and(ApprovalPushNotice::where('client_account_id', $this->account->id)->exists())->toBeFalse();
});

test('the approval switch in the app is saved', function () {
    expect($this->user->wantsNotification('work_order_approvals_push'))->toBeTrue();

    Sanctum::actingAs($this->user);
    $this->putJson(route('api.v1.notification-preferences.update'), ['work_order_approvals_push' => false])
        ->assertOk()
        ->assertJsonPath('data.work_order_approvals_push', false);
});

/**
 * The approval check as `schedule:run` sees it at a given Houston time. Laravel
 * reads the clock when the schedule is defined, and production defines it
 * afresh every minute, so the test does the same after moving the clock.
 */
function approvalCheckAt(string $houstonTime): Event
{
    test()->travelTo(CarbonImmutable::parse($houstonTime, 'America/Chicago'));
    app()->instance(Schedule::class, new Schedule);
    Illuminate\Support\Facades\Schedule::clearResolvedInstance(Schedule::class);
    require base_path('routes/console.php');

    return collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'approvals:notify'));
}

test('runs every half hour in the daytime, Houston time', function () {
    $event = approvalCheckAt('2026-10-05 12:00');

    expect($event->expression)->toBe('*/30 * * * *')
        ->and($event->timezone)->toBe('America/Chicago')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->filtersPass(app()))->toBeTrue()
        ->and(approvalCheckAt('2026-10-05 07:30')->filtersPass(app()))->toBeFalse()
        ->and(approvalCheckAt('2026-10-05 20:30')->filtersPass(app()))->toBeFalse()
        ->and(approvalCheckAt('2026-10-05 08:00')->filtersPass(app()))->toBeTrue();
});
