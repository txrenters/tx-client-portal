<?php

use App\Console\Commands\RecordApprovalHistory;
use App\Enums\StaffRole;
use App\Models\PropertywareApproval;
use App\Models\User;
use App\Services\Propertyware\ApprovalHistory;
use App\Services\Propertyware\PropertywareException;
use App\Services\Propertyware\PropertywareSoap;
use App\Services\Propertyware\PropertywareUnavailableException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.propertyware.soap.url' => 'https://soap.propertyware.test/pw/services/PWServices',
        'services.propertyware.soap.username' => 'portal',
        'services.propertyware.soap.password' => 'secret',
    ]);

    Http::fake([
        '*/workorders*' => Http::response([
            ['id' => 8247771258, 'number' => 44224, 'approved' => true, 'lastModifiedDateTime' => '2026-10-28T12:00:00.000Z'],
        ]),
    ]);
});

/**
 * @return array{approved: bool, approvedDate: ?string, approvedBy: ?string, approvedByEmail: ?string, comment: ?string}
 */
function propertywareApproval(array $overrides = []): array
{
    return [
        'approved' => true,
        'approvedDate' => '2026-10-28',
        'approvedBy' => 'Maintenance Dashboard',
        'approvedByEmail' => 'md@texasrenters.com',
        'comment' => 'I approve this work order. - Olivia Owner, 10/28/2026',
        ...$overrides,
    ];
}

test('keeps a new version each time the approval comment changes in Propertyware', function () {
    $soap = $this->mock(PropertywareSoap::class);
    $soap->shouldReceive('isConfigured')->andReturnTrue();
    $soap->shouldReceive('workOrderApproval')->with(44224)->andReturn(
        propertywareApproval(),
        propertywareApproval(),
        propertywareApproval(['comment' => 'Approved, but use the cheaper vendor.', 'approvedBy' => 'Sam Staff']),
    );

    $this->artisan('approvals:history')->assertSuccessful();
    $this->artisan('approvals:history')->assertSuccessful();
    $this->artisan('approvals:history')->assertSuccessful();

    expect(PropertywareApproval::orderBy('id')->get(['work_order_id', 'work_order_number', 'approved_by', 'approval_comment'])->toArray())->toBe([
        ['work_order_id' => '8247771258', 'work_order_number' => '44224', 'approved_by' => 'Maintenance Dashboard', 'approval_comment' => 'I approve this work order. - Olivia Owner, 10/28/2026'],
        ['work_order_id' => '8247771258', 'work_order_number' => '44224', 'approved_by' => 'Sam Staff', 'approval_comment' => 'Approved, but use the cheaper vendor.'],
    ]);
});

test('keeps nothing for a work order that was never approved or commented on', function () {
    $soap = $this->mock(PropertywareSoap::class);
    $soap->shouldReceive('isConfigured')->andReturnTrue();
    $soap->shouldReceive('workOrderApproval')->andReturn(propertywareApproval([
        'approved' => false, 'approvedDate' => null, 'approvedBy' => null, 'approvedByEmail' => null, 'comment' => null,
    ]));

    $this->artisan('approvals:history')->assertSuccessful();

    expect(PropertywareApproval::count())->toBe(0);
});

test('reads only work orders changed since the last run', function () {
    $this->freezeTime();
    Cache::forever(ApprovalHistory::SINCE, '2026-10-28T09:00:00+00:00');
    $soap = $this->mock(PropertywareSoap::class);
    $soap->shouldReceive('isConfigured')->andReturnTrue();
    $soap->shouldReceive('workOrderApproval')->andReturn(propertywareApproval());

    $this->artisan('approvals:history')->assertSuccessful();

    Http::assertSent(fn ($request): bool => str_contains(urldecode($request->url()), 'lastModifiedDateTimeStart=2026-10-28T08:50:00Z'));
    expect(Cache::get(ApprovalHistory::SINCE))->toBe(now()->toIso8601String());
});

test('reads the same work orders again next run when Propertyware fails', function () {
    Cache::forever(ApprovalHistory::SINCE, '2026-10-28T09:00:00+00:00');
    $soap = $this->mock(PropertywareSoap::class);
    $soap->shouldReceive('isConfigured')->andReturnTrue();
    $soap->shouldReceive('workOrderApproval')->andThrow(new PropertywareException('Propertyware is down.'));

    $this->artisan('approvals:history')->assertSuccessful();

    expect(Cache::get(ApprovalHistory::SINCE))->toBe('2026-10-28T09:00:00+00:00')
        ->and(PropertywareApproval::count())->toBe(0);
});

test('a short Propertyware outage is waited out, and a long one fails the run', function () {
    $this->mock(PropertywareSoap::class)->shouldReceive('isConfigured')->andReturnTrue();
    $this->mock(ApprovalHistory::class)->shouldReceive('record')
        ->andThrow(new PropertywareUnavailableException('Propertyware returned 503 for [workorders].'));

    $this->artisan('approvals:history')->assertSuccessful();
    $this->travel(RecordApprovalHistory::UNAVAILABLE_GRACE_MINUTES - 30)->minutes();
    $this->artisan('approvals:history')->assertSuccessful();
    $this->travel(30)->minutes();
    $this->artisan('approvals:history')->assertFailed();
});

test('the outage clock restarts once a run succeeds', function () {
    Cache::forever(RecordApprovalHistory::UNAVAILABLE_SINCE, now()->subHours(5)->toIso8601String());
    $soap = $this->mock(PropertywareSoap::class);
    $soap->shouldReceive('isConfigured')->andReturnTrue();
    $soap->shouldReceive('workOrderApproval')->andReturn(propertywareApproval());

    $this->artisan('approvals:history')->assertSuccessful();

    expect(Cache::has(RecordApprovalHistory::UNAVAILABLE_SINCE))->toBeFalse();
});

test('Propertyware rejecting the request fails the run straight away', function () {
    $this->mock(PropertywareSoap::class)->shouldReceive('isConfigured')->andReturnTrue();
    $this->mock(ApprovalHistory::class)->shouldReceive('record')
        ->andThrow(new PropertywareException('Propertyware returned 401 for [workorders].'));

    $this->artisan('approvals:history')->assertFailed();
});

test('admins see Propertyware approval changes in the activity feed', function () {
    PropertywareApproval::factory()->create(['work_order_number' => '44224', 'approval_comment' => 'Use the cheaper vendor.', 'approved_by' => 'Sam Staff']);
    $admin = User::factory()->create(['staff_role' => StaffRole::Admin]);

    $this->actingAs($admin)
        ->get(route('admin.activity'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->loadDeferredProps(fn ($page) => $page->where('activity', fn ($events) => collect($events)->contains(fn ($event) => $event['type'] === 'propertyware_approval'
            && $event['summary'] === 'Work order #44224 approved in Propertyware: "Use the cheaper vendor."'
            && $event['actor'] === 'Sam Staff'))));
});

test('plain staff do not see Propertyware approval changes', function () {
    PropertywareApproval::factory()->create();
    $staff = User::factory()->create(['staff_role' => StaffRole::Staff]);

    $this->actingAs($staff)
        ->get(route('admin.activity'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->loadDeferredProps(fn ($page) => $page->where('activity', fn ($events) => collect($events)->doesntContain('type', 'propertyware_approval'))));
});
