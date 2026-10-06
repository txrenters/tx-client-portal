<?php

use App\Enums\ClientRole;
use App\Models\DemoRecord;
use App\Models\HelpRequest;
use App\Models\Referral;
use App\Models\User;
use App\Models\WorkOrderApproval;
use App\Services\Admin\PortalActivity;
use App\Services\Demo\DemoData;
use App\Services\Demo\DemoPropertywareSoap;
use App\Services\Help\HelpDesk;
use App\Services\Propertyware\MaintenanceSummary;
use App\Services\Propertyware\PropertywareSoap;
use App\Services\Propertyware\WorkOrderApprovals;
use App\Services\Referrals\ReferralProgram;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('local');
});

test('the tenant\'s maintenance request is kept for the demo and listed', function () {
    [$user, $account] = demoLogin(ClientRole::Resident);
    Sanctum::actingAs($user);

    $this->postJson(route('api.v1.accounts.maintenance.store', $account), [
        'category' => MaintenanceSummary::clientCategories()[0],
        'description' => 'Smoke alarm keeps chirping',
        'urgency' => 'normal',
        'entry' => 'anytime',
    ])->assertSuccessful();

    $this->getJson(route('api.v1.accounts.maintenance.index', [$account, 'status' => 'all']))->assertOk()
        ->assertJsonFragment(['description' => 'Smoke alarm keeps chirping']);
    expect(DemoRecord::payloads('workorder'))->toHaveCount(1);
});

test('the owner\'s document upload is kept and downloads again', function () {
    [$user, $account] = demoLogin(ClientRole::Owner);
    Sanctum::actingAs($user);

    $created = $this->post(route('api.v1.accounts.documents.store', $account), [
        'file' => UploadedFile::fake()->createWithContent('W-9.pdf', '%PDF-1.4 w9'),
        'description' => 'Signed W-9',
        // The demo owner has two homes, so the reviewer picks one, as the upload dialog asks.
        'attach_to' => 'property',
        'target_id' => DemoData::HOME_LEASED,
    ], ['Accept' => 'application/json'])->assertSuccessful();

    $this->getJson(route('api.v1.accounts.documents.index', $account))->assertOk()->assertJsonFragment(['description' => 'Signed W-9']);

    $this->get(route('api.v1.accounts.documents.show', [$account, $created->json('data.id')]))->assertOk();
    expect($this->get(route('api.v1.accounts.documents.show', [$account, $created->json('data.id')]))->streamedContent())->toBe('%PDF-1.4 w9');
});

test('the owner approves the waiting work order and it is never sent to Propertyware', function () {
    config(['services.propertyware.approval_push' => false]);
    [$user, $account] = demoLogin(ClientRole::Owner);
    Sanctum::actingAs($user);

    $this->postJson(route('api.v1.accounts.maintenance.approval', [$account, DemoData::WORK_ORDER_APPROVAL]), ['decision' => 'approved'])
        ->assertSuccessful();

    expect(WorkOrderApproval::sole()->decision)->toBe('approved');

    // Later, the scheduled push runs with the real settings, outside any demo request.
    expect(app(WorkOrderApprovals::class)->pushPending())->toBe(['sent' => 0, 'failed' => 0]);
});

test('messages open and reply inside the portal', function () {
    [$user, $account] = demoLogin(ClientRole::Owner);
    Sanctum::actingAs($user);

    $thread = $this->postJson(route('api.v1.accounts.messages.store', $account), ['category' => 'leasing', 'body' => 'When will the vacant home be listed?'])
        ->assertSuccessful()->json('data.id');
    $this->postJson(route('api.v1.accounts.messages.reply', [$account, $thread]), ['body' => 'Thanks!'])->assertSuccessful();

    $this->getJson(route('api.v1.accounts.messages.show', [$account, $thread]))->assertOk()
        ->assertJsonFragment(['body' => 'When will the vacant home be listed?'])
        ->assertJsonFragment(['body' => 'Thanks!']);
});

test('a referral and a help request from a demo login reach nobody', function () {
    config(['services.admin_emails' => ['staff@example.com']]);
    Mail::fake();
    Notification::fake();
    [$user, $account] = demoLogin(ClientRole::Resident);
    Sanctum::actingAs($user);

    $this->postJson(route('api.v1.accounts.referrals.store', $account), [
        'first_name' => 'Pat', 'last_name' => 'Example', 'email' => 'pat@example.com', 'phone' => '281-555-0199', 'property_address' => '1 Example St',
    ])->assertSuccessful();
    $this->postJson(route('api.v1.help-requests.store'), ['topic' => 'other', 'message' => 'Just trying the help form out.'])->assertSuccessful();

    Http::assertNotSent(fn ($request) => ! str_ends_with((string) parse_url($request->url(), PHP_URL_HOST), '.demo.invalid'));
    Mail::assertNothingOutgoing();
    Notification::assertNothingSent();
});

test('demo help requests and referrals stay out of the staff views and counts', function () {
    config(['services.admin_emails' => ['staff@example.com']]);
    [$demo, $account] = demoLogin(ClientRole::Resident);
    $real = User::factory()->create();
    HelpRequest::factory()->for($demo)->create();
    HelpRequest::factory()->for($real)->create();
    Referral::factory()->create(['user_id' => $demo->id, 'first_name' => 'Demo']);
    Referral::factory()->create(['user_id' => $real->id, 'first_name' => 'Real']);

    expect(app(HelpDesk::class)->openCount())->toBe(1)
        ->and(app(HelpDesk::class)->forStaff()->total())->toBe(1)
        ->and(app(ReferralProgram::class)->forStaff()->pluck('firstName')->all())->toBe(['Real'])
        ->and(collect(app(PortalActivity::class)->recent())->where('type', 'help_request')->pluck('actor')->all())->toBe([$real->email]);
});

test('with approval pushing on, a demo approval and decline are stamped synced and never leave the demo', function (string $decision) {
    config(['services.propertyware.approval_push' => true, 'services.propertyware.approval_test_mode' => false]);
    [$user, $account] = demoLogin(ClientRole::Owner);
    Sanctum::actingAs($user);

    $this->postJson(route('api.v1.accounts.maintenance.approval', [$account, DemoData::WORK_ORDER_APPROVAL]), ['decision' => $decision])
        ->assertSuccessful();

    expect(WorkOrderApproval::sole())->decision->toBe($decision)->propertyware_synced_at->not->toBeNull()
        ->and(app(PropertywareSoap::class))->toBeInstanceOf(DemoPropertywareSoap::class);
    Http::assertNotSent(fn ($request) => ! str_ends_with((string) parse_url($request->url(), PHP_URL_HOST), '.demo.invalid'));
})->with(['approved', 'declined']);

test('demo work-order approvals stay out of the staff activity feed', function () {
    [$demo, $demoAccount] = demoLogin(ClientRole::Owner);
    $real = User::factory()->create();
    WorkOrderApproval::factory()->for($demo)->for($demoAccount)->create(['approver_name' => 'Demo Approver']);
    WorkOrderApproval::factory()->for($real)->create(['approver_name' => 'Real Approver']);

    $actors = collect(app(PortalActivity::class)->recent())->filter(fn ($row) => str_starts_with($row['type'], 'work_order_'))->pluck('actor')->all();

    expect($actors)->toBe(['Real Approver']);
});
