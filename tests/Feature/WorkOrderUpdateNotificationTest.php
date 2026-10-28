<?php

use App\Enums\ClientRole;
use App\Models\ClientAccount;
use App\Models\PushToken;
use App\Models\User;
use App\Models\WorkOrderStatusNotice;
use App\Notifications\WorkOrderUpdatedNotification;
use App\Services\Propertyware\Data\ClientScope;
use App\Services\Propertyware\Data\WorkOrder;
use App\Services\Propertyware\MaintenanceSummary;
use App\Services\Propertyware\PropertywareDirectory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->account = ClientAccount::factory()->resident()->for($this->user)->create(['propertyware_id' => '500']);

    $this->mock(PropertywareDirectory::class)
        ->shouldReceive('scopeFor')
        ->andReturn(new ClientScope(ClientRole::Resident, '500'));
});

/**
 * Propertyware reports these work orders on each run, in order.
 *
 * @param  array<int, array<int, array<string, mixed>>>  $runs
 */
function workOrdersPerRun(array ...$runs): void
{
    test()->mock(MaintenanceSummary::class)
        ->shouldReceive('forClient')
        ->andReturn(...array_map(
            fn (array $records) => collect($records)->map(fn (array $record): WorkOrder => WorkOrder::fromArray([
                'id' => 7001, 'number' => 44100, 'description' => "Kitchen sink leaking\nUnder the cabinet", 'status' => 'Open', ...$record,
            ])),
            $runs,
        ));
}

test('tells the tenant when their work order is scheduled, once', function () {
    Notification::fake();
    workOrdersPerRun(
        [[]],
        [['scheduledEndDate' => '2026-11-03']],
        [['scheduledEndDate' => '2026-11-03']],
    );

    $this->artisan('work-orders:notify-updates')->assertSuccessful();
    $this->artisan('work-orders:notify-updates')->assertSuccessful();
    $this->artisan('work-orders:notify-updates')->assertSuccessful();

    Notification::assertSentToTimes($this->user, WorkOrderUpdatedNotification::class, 1);
    Notification::assertSentTo($this->user, fn (WorkOrderUpdatedNotification $notification): bool => $notification->notice['title'] === 'Work order #44100 is scheduled'
        && $notification->notice['message'] === "Kitchen sink leaking\nScheduled for Nov 3, 2026."
        && $notification->notice['url'] === route('resident.maintenance.show', '7001')
        && $notification->notice['account_id'] === $this->account->id);
});

test('does not tell anyone about a work order the first time it is seen', function () {
    Notification::fake();
    workOrdersPerRun([['status' => 'Closed', 'completedDate' => '2026-10-01']]);

    $this->artisan('work-orders:notify-updates')->assertSuccessful();

    Notification::assertNothingSent();
    expect(WorkOrderStatusNotice::sole()->status->value)->toBe('completed');
});

test('records a work order going back to open without telling anyone', function () {
    Notification::fake();
    workOrdersPerRun(
        [['scheduledEndDate' => '2026-11-03']],
        [[]],
    );

    $this->artisan('work-orders:notify-updates')->assertSuccessful();
    $this->artisan('work-orders:notify-updates')->assertSuccessful();

    Notification::assertNothingSent();
    expect(WorkOrderStatusNotice::sole()->status->value)->toBe('open');
});

test('an owner is sent to their own work order page', function () {
    Notification::fake();
    $this->account->update(['role' => ClientRole::Owner]);
    workOrdersPerRun([[]], [['status' => 'Closed', 'completedDate' => '2026-10-28']]);

    $this->artisan('work-orders:notify-updates')->assertSuccessful();
    $this->artisan('work-orders:notify-updates')->assertSuccessful();

    Notification::assertSentTo($this->user, fn (WorkOrderUpdatedNotification $notification): bool => $notification->notice['title'] === 'Work order #44100 is complete'
        && $notification->notice['url'] === route('owner.maintenance.show', '7001'));
});

test('is listed in the notifications and pushed unless work order updates are off', function () {
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 't1']]])]);
    PushToken::factory()->for($this->user)->create();
    $quiet = User::factory()->create(['notification_preferences' => ['work_order_updates_push' => false]]);
    PushToken::factory()->for($quiet)->create();
    $quietAccount = ClientAccount::factory()->resident()->for($quiet)->create();
    $workOrder = WorkOrder::fromArray(['id' => 7001, 'number' => 44100, 'description' => 'Kitchen sink leaking', 'status' => 'Canceled']);

    $this->user->notify(new WorkOrderUpdatedNotification($this->account, $workOrder));
    $quiet->notify(new WorkOrderUpdatedNotification($quietAccount, $workOrder));

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->data()[0]['title'] === 'Work order #44100 was canceled'
        && $request->data()[0]['data']['type'] === 'work_order_update');

    $this->actingAs($this->user)
        ->get(route('notifications.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('notices', 1)
            ->where('notices.0.type', 'work_order_update')
            ->where('notices.0.title', 'Work order #44100 was canceled'));
});
