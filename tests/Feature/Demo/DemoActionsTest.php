<?php

use App\Enums\ClientRole;
use App\Models\DemoRecord;
use App\Models\WorkOrderApproval;
use App\Services\Demo\DemoData;
use App\Services\Propertyware\MaintenanceSummary;
use App\Services\Propertyware\WorkOrderApprovals;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
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

    $this->post(route('api.v1.accounts.documents.store', $account), [
        'file' => UploadedFile::fake()->createWithContent('W-9.pdf', '%PDF-1.4 w9'),
        'description' => 'Signed W-9',
        // The demo owner has two homes, so the reviewer picks one, as the upload dialog asks.
        'attach_to' => 'property',
        'target_id' => DemoData::HOME_LEASED,
    ], ['Accept' => 'application/json'])->assertSuccessful();

    $this->getJson(route('api.v1.accounts.documents.index', $account))->assertOk()->assertJsonFragment(['description' => 'Signed W-9']);
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
    Mail::fake();
    [$user, $account] = demoLogin(ClientRole::Resident);
    Sanctum::actingAs($user);

    $this->postJson(route('api.v1.accounts.referrals.store', $account), [
        'first_name' => 'Pat', 'last_name' => 'Example', 'email' => 'pat@example.com', 'phone' => '281-555-0199', 'property_address' => '1 Example St',
    ])->assertSuccessful();
    $this->postJson(route('api.v1.help-requests.store'), ['topic' => 'other', 'message' => 'Just trying the help form out.'])->assertSuccessful();

    Http::assertNotSent(fn ($request) => ! str_ends_with((string) parse_url($request->url(), PHP_URL_HOST), '.demo.invalid'));
    Mail::assertNothingOutgoing();
});
