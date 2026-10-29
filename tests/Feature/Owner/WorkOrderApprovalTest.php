<?php

use App\Models\ClientAccount;
use App\Models\User;
use App\Models\WorkOrderApproval;
use App\Services\Propertyware\PropertywareException;
use App\Services\Propertyware\PropertywareSoap;
use App\Services\Propertyware\WorkOrderApprovals;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['services.propertyware.discover_accounts' => false]);

    $workOrder = fn (string $id, array $overrides = []): array => [
        'id' => $id, 'number' => 40000 + (int) $id, 'description' => "Repair {$id}", 'status' => 'Open',
        'buildingID' => 7, 'portfolioID' => 2, 'publishToOwnerPortal' => true, 'approved' => false,
        'lastModifiedDateTime' => '2026-09-10T10:00:00.000Z', ...$overrides,
    ];

    Http::fake([
        '*/contacts/*' => Http::response(['id' => '500', 'type' => 'OWNER', 'attachedLeases' => [], 'attachedPortfolios' => [['id' => 2, 'name' => 'Portfolio']]]),
        '*/buildings*' => Http::response([['id' => 7, 'portfolioID' => 2, 'address' => ['address' => '12 Elm St']]]),
        '*/units*' => Http::response([]),
        '*/docs*' => Http::response([]),
        '*/workorders/1' => Http::response($workOrder('1')),
        '*/workorders*' => Http::response([
            $workOrder('1'),
            $workOrder('2', ['approved' => true, 'approvedDate' => '2026-09-01']),
            $workOrder('3', ['status' => 'Closed', 'completedDate' => '2026-09-05T10:00:00.000Z']),
        ]),
    ]);

    $this->user = User::factory()->create(['name' => 'Olivia Owner']);
    $this->account = ClientAccount::factory()->owner()->for($this->user)->create(['propertyware_id' => '500']);
});

test('flags only open, unapproved work orders as needing the owner\'s approval', function () {
    $this->actingAs($this->user);

    $response = partialGet(route('owner.maintenance.index', ['status' => 'all']), 'owner/Maintenance', 'maintenance')->assertOk();

    expect(collect($response->json('props.maintenance.workOrders.data'))->pluck('awaitingApproval', 'id')->all())
        ->toEqual(['1' => true, '2' => false, '3' => false]);
});

test('records who approved, when and from where', function () {
    $this->actingAs($this->user)
        ->withHeader('User-Agent', 'PortalTest/1.0')
        ->post(route('owner.maintenance.approval', '1'), ['decision' => 'approved'])
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', 'Work order approved. Thank you.');

    $approval = WorkOrderApproval::sole();
    expect($approval)->user_id->toBe($this->user->id)
        ->client_account_id->toBe($this->account->id)
        ->propertyware_contact_id->toBe('500')
        ->work_order_id->toBe('1')
        ->work_order_number->toBe('40001')
        ->decision->toBe('approved')
        ->approver_name->toBe('Olivia Owner')
        ->user_agent->toBe('PortalTest/1.0')
        ->and($approval->ip_address)->not->toBeNull();
});

test('writes the real approver into the approval comment', function () {
    $this->travelTo('2026-09-19 20:04:00 UTC');
    $approval = WorkOrderApproval::factory()->for($this->user)->for($this->account)->create([
        'propertyware_contact_id' => '500', 'approver_name' => 'Olivia Owner', 'decided_at' => now(), 'note' => 'Go ahead',
    ]);

    expect(app(WorkOrderApprovals::class)->approvalComment($approval))
        ->toBe('I approve this work order. - Olivia Owner, 09/19/2026. Comment: Go ahead');

    $approval->update(['note' => null]);
    expect(app(WorkOrderApprovals::class)->approvalComment($approval))
        ->toBe('I approve this work order. - Olivia Owner, 09/19/2026');
});

test('an owner can decline with a note and still approve later', function () {
    $this->actingAs($this->user)
        ->post(route('owner.maintenance.approval', '1'), ['decision' => 'declined', 'note' => 'Please get a second quote'])
        ->assertInertiaFlash('toast.message', 'Got it. We have let your team know you declined.');

    $this->get(route('owner.maintenance.show', '1'))
        ->assertInertia(fn ($page) => $page
            ->where('approval.decision', 'declined')
            ->where('approval.note', 'Please get a second quote')
            ->where('approval.canDecide', true));

    $this->post(route('owner.maintenance.approval', '1'), ['decision' => 'approved'])->assertSessionHasNoErrors();

    expect(WorkOrderApproval::query()->pluck('decision')->all())->toBe(['declined', 'approved']);
});

test('refuses a decision on work orders that are not waiting on the owner', function (string $workOrderId) {
    $this->actingAs($this->user)
        ->post(route('owner.maintenance.approval', $workOrderId), ['decision' => 'approved'])
        ->assertSessionHasErrors(['decision' => 'This work order is not waiting for your approval.']);

    expect(WorkOrderApproval::count())->toBe(0);
})->with([
    'already approved in propertyware' => ['2'],
    'closed' => ['3'],
    'not the owner\'s' => ['999'],
]);

test('does not ask again once the owner approved', function () {
    $this->actingAs($this->user)->post(route('owner.maintenance.approval', '1'), ['decision' => 'approved']);

    $this->post(route('owner.maintenance.approval', '1'), ['decision' => 'approved'])->assertSessionHasErrors('decision');

    expect(WorkOrderApproval::count())->toBe(1);
});

test('lets the app approve and returns the new state', function () {
    Sanctum::actingAs($this->user);

    $this->postJson(route('api.v1.accounts.maintenance.approval', [$this->account, '1']), ['decision' => 'approved'])
        ->assertOk()
        ->assertJsonPath('data.decision', 'approved')
        ->assertJsonPath('data.canDecide', false)
        ->assertJsonPath('data.decidedBy', 'Olivia Owner');
});

test('tenants cannot approve work orders', function () {
    $tenant = User::factory()->create();
    $account = ClientAccount::factory()->resident()->for($tenant)->create(['propertyware_id' => '900']);
    Sanctum::actingAs($tenant);

    $this->postJson(route('api.v1.accounts.maintenance.approval', [$account, '1']), ['decision' => 'approved'])
        ->assertJsonValidationErrors('decision');

    expect(WorkOrderApproval::count())->toBe(0);
});

test('keeps the approval in the portal while sending to propertyware is turned off', function () {
    config(['services.propertyware.approval_push' => false]);
    $this->mock(PropertywareSoap::class)->shouldNotReceive('approveWorkOrder');

    $this->actingAs($this->user)->post(route('owner.maintenance.approval', '1'), ['decision' => 'approved']);

    expect(WorkOrderApproval::sole())->propertyware_synced_at->toBeNull()
        ->sync_error->toBe('Sending approvals to Propertyware is turned off.');
    $this->get(route('owner.maintenance.show', '1'))->assertInertia(fn ($page) => $page->where('approval.pendingSync', true));
});

test('approves in propertyware with the owner and their comment in the approval comment', function () {
    config(['services.propertyware.approval_push' => true]);
    $this->travelTo('2026-09-19 20:04:00 UTC');
    $this->mock(PropertywareSoap::class)
        ->shouldReceive('approveWorkOrder')->once()
        ->with(1, '2026-09-19T15:04:00', 'I approve this work order. - Olivia Owner, 09/19/2026. Comment: Use the same plumber', true);

    $this->actingAs($this->user)->post(route('owner.maintenance.approval', '1'), ['decision' => 'approved', 'note' => 'Use the same plumber']);

    expect(WorkOrderApproval::sole())->propertyware_synced_at->not->toBeNull()->sync_error->toBeNull();
});

test('sends curly quotes and dashes in the owner\'s comment as plain ones propertyware can store', function () {
    $this->travelTo('2026-09-19 20:04:00 UTC');
    $approval = WorkOrderApproval::factory()->for($this->user)->for($this->account)->create([
        'approver_name' => 'Olivia Owner', 'decided_at' => now(),
        'note' => "It\u{2019}s the tenant\u{2019}s \u{2014} \u{201C}per lease\u{201D}\u{2026}",
    ]);

    expect(app(WorkOrderApprovals::class)->approvalComment($approval))
        ->toBe('I approve this work order. - Olivia Owner, 09/19/2026. Comment: It\'s the tenant\'s - "per lease"...');
});

test('puts the owner\'s decline and reason in the propertyware approval comment', function () {
    config(['services.propertyware.approval_push' => true]);
    $this->travelTo('2026-09-19 20:04:00 UTC');
    $this->mock(PropertywareSoap::class)
        ->shouldReceive('approveWorkOrder')->once()
        ->with(1, '2026-09-19T15:04:00', 'I decline this work order. - Olivia Owner, 09/19/2026. Comment: Please get a second quote', false);

    $this->actingAs($this->user)->post(route('owner.maintenance.approval', '1'), ['decision' => 'declined', 'note' => 'Please get a second quote']);

    expect(WorkOrderApproval::sole())->decision->toBe('declined')->propertyware_synced_at->not->toBeNull();
});

test('a late retry never sends an older decision over a newer one', function () {
    config(['services.propertyware.approval_push' => true]);
    $this->mock(PropertywareSoap::class)
        ->shouldReceive('approveWorkOrder')->once()->with(1, Mockery::any(), Mockery::pattern('/^I approve/'), true);

    $declined = WorkOrderApproval::factory()->for($this->user)->for($this->account)->create([
        'work_order_id' => '1', 'decision' => 'declined', 'decided_at' => now()->subHour(), 'sync_error' => 'timeout',
    ]);
    WorkOrderApproval::factory()->for($this->user)->for($this->account)->create([
        'work_order_id' => '1', 'decision' => 'approved', 'decided_at' => now(), 'sync_error' => 'timeout',
    ]);

    $this->artisan('approvals:push')->assertSuccessful();

    expect($declined->refresh())->propertyware_synced_at->toBeNull()->sync_error->toBe(WorkOrderApprovals::SUPERSEDED);
});

test('sends an approval propertyware refused again later', function () {
    config(['services.propertyware.approval_push' => true]);
    $this->mock(PropertywareSoap::class)
        ->shouldReceive('approveWorkOrder')->once()->andThrow(new PropertywareException('Propertyware refused the approval: timeout'))
        ->shouldReceive('approveWorkOrder')->once();

    $this->actingAs($this->user)->post(route('owner.maintenance.approval', '1'), ['decision' => 'approved'])
        ->assertInertiaFlash('toast.message', 'Work order approved. Thank you.');
    expect(WorkOrderApproval::sole()->propertyware_synced_at)->toBeNull();

    $this->artisan('approvals:push')->expectsOutputToContain('Sent 1 approval(s) to Propertyware; 0 still waiting.')->assertSuccessful();

    expect(WorkOrderApproval::sole()->propertyware_synced_at)->not->toBeNull();
});

test('the needs-approval view lists only work orders waiting on the owner', function () {
    $this->actingAs($this->user);

    $response = partialGet(route('owner.maintenance.index', ['status' => 'all', 'approval' => 'needed']), 'owner/Maintenance', 'maintenance')->assertOk();

    expect(collect($response->json('props.maintenance.workOrders.data'))->pluck('id')->all())->toBe(['1'])
        ->and($response->json('props.maintenance.awaitingApproval'))->toBe(1);

    $this->post(route('owner.maintenance.approval', '1'), ['decision' => 'approved']);

    $response = partialGet(route('owner.maintenance.index', ['status' => 'all', 'approval' => 'needed']), 'owner/Maintenance', 'maintenance');

    expect($response->json('props.maintenance.workOrders.data'))->toBeEmpty()
        ->and($response->json('props.maintenance.awaitingApproval'))->toBe(0);
});

test('shows the work orders needing approval on the dashboard and notifications', function () {
    $this->actingAs($this->user);

    $dashboard = partialGet(route('owner.dashboard'), 'owner/Dashboard', 'approvals')->assertOk();
    expect($dashboard->json('props.approvals.count'))->toBe(1)
        ->and($dashboard->json('props.approvals.items.0'))->toMatchArray(['id' => '1', 'awaitingApproval' => true]);

    $notifications = partialGet(route('notifications.index'), 'client/Notifications', 'approvals')->assertOk();
    expect($notifications->json('props.approvals.count'))->toBe(1);

    $this->getJson(route('notifications.latest'))->assertOk()
        ->assertJsonPath('count', 1)
        ->assertJsonPath('approvals.items.0.id', '1')
        ->assertJsonPath('messages.count', 0);
});

test('the bell stops counting a work order once the owner approved it', function () {
    $this->actingAs($this->user)->post(route('owner.maintenance.approval', '1'), ['decision' => 'approved']);

    $this->getJson(route('notifications.latest'))->assertJsonPath('count', 0)->assertJsonPath('approvals.items', []);
});

test('residents have no approvals to count', function () {
    $tenant = User::factory()->create();
    ClientAccount::factory()->resident()->for($tenant)->create(['propertyware_id' => '900']);

    $this->actingAs($tenant)->getJson(route('notifications.latest'))
        ->assertJsonPath('count', 0)
        ->assertJsonPath('portal', 'resident')
        ->assertJsonPath('approvals', ['count' => 0, 'items' => []]);
    Http::assertNothingSent();
});

test('the app lists the work orders needing approval', function () {
    Sanctum::actingAs($this->user);

    $this->getJson(route('api.v1.accounts.approvals.index', $this->account))
        ->assertOk()
        ->assertJsonPath('meta.count', 1)
        ->assertJsonPath('data.0.id', '1');

    $this->getJson(route('api.v1.accounts.maintenance.index', [$this->account, 'approval' => 'needed']))
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.awaitingApproval', true);

    $this->getJson(route('api.v1.accounts.dashboard', $this->account))
        ->assertOk()
        ->assertJsonPath('data.approvals.count', 1);
});

test('test mode opens the approval on any owner work order but never sends it', function () {
    config(['services.propertyware.approval_push' => true, 'services.propertyware.approval_test_mode' => true]);
    $this->mock(PropertywareSoap::class)->shouldNotReceive('approveWorkOrder');

    $this->actingAs($this->user)->post(route('owner.maintenance.approval', '2'), ['decision' => 'approved', 'note' => 'Testing'])
        ->assertSessionHasNoErrors();

    expect(WorkOrderApproval::sole())->propertyware_synced_at->toBeNull()->sync_error->toBe(WorkOrderApprovals::TEST_APPROVAL);

    config(['services.propertyware.approval_test_mode' => false]);
    $this->artisan('approvals:push')->expectsOutputToContain('Sent 0 approval(s)')->assertSuccessful();
});
