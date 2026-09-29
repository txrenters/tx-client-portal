<?php

use App\Enums\ReferralStatus;
use App\Jobs\SendReferralToLeads;
use App\Models\Referral;
use App\Models\Setting;
use App\Models\User;
use App\Services\Referrals\ReferralProgram;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config(['services.admin_emails' => ['staff@texasrenters.com']]);
    $this->admin = User::factory()->create(['email' => 'staff@texasrenters.com']);
});

test('staff see every referral with the owner\'s contact details and who sent it', function () {
    Referral::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@example.com', 'referrer_name' => 'Rita Referrer']);

    $this->actingAs($this->admin)->get(route('admin.referrals.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/Referrals')
            ->where('reward', ReferralProgram::DEFAULT_REWARD)
            ->where('referrals.data.0.name', 'Jane Doe')
            ->where('referrals.data.0.email', 'jane@example.com')
            ->where('referrals.data.0.referrerName', 'Rita Referrer'));
});

test('narrows referrals by status and search', function () {
    Referral::factory()->create(['first_name' => 'Newest', 'property_address' => '1 Elm St']);
    Referral::factory()->status(ReferralStatus::Signed)->create(['first_name' => 'Signed', 'property_address' => '2 Oak Ave']);

    $this->actingAs($this->admin)->get(route('admin.referrals.index', ['status' => 'signed']))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('referrals.data', 1)->where('referrals.data.0.firstName', 'Signed'));

    $this->actingAs($this->admin)->get(route('admin.referrals.index', ['search' => 'ELM']))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('referrals.data', 1)->where('referrals.data.0.firstName', 'Newest'));
});

test('staff move a referral along, which the referrer then sees', function () {
    $referral = Referral::factory()->create();

    $this->actingAs($this->admin)
        ->patch(route('admin.referrals.update', $referral), ['status' => 'contacted'])
        ->assertRedirect();

    expect($referral->fresh())
        ->status->toBe(ReferralStatus::Contacted)
        ->status_changed_at->not->toBeNull();
});

test('staff set the reward wording, and clearing it brings the card\'s back', function () {
    $this->actingAs($this->admin)->put(route('admin.referrals.reward'), ['reward' => 'Get $250 when they sign.'])->assertRedirect();
    expect(app(ReferralProgram::class)->reward())->toBe('Get $250 when they sign.');

    $this->actingAs($this->admin)->put(route('admin.referrals.reward'), ['reward' => ''])->assertRedirect();
    expect(app(ReferralProgram::class)->reward())->toBe(ReferralProgram::DEFAULT_REWARD)
        ->and(Setting::valueOf(ReferralProgram::REWARD_SETTING))->toBeNull();
});

test('clients cannot see or change referrals in the admin area', function () {
    $client = User::factory()->create();
    $referral = Referral::factory()->create();

    $this->actingAs($client)->get(route('admin.referrals.index'))->assertForbidden();
    $this->actingAs($client)->patch(route('admin.referrals.update', $referral), ['status' => 'signed'])->assertForbidden();
    $this->actingAs($client)->put(route('admin.referrals.reward'), ['reward' => 'Free money'])->assertForbidden();

    expect($referral->fresh()->status)->toBe(ReferralStatus::New);
});

test('staff can send a referral the leads webhook refused to it again', function () {
    Queue::fake();
    config(['services.leads.webhook_url' => 'https://leads.example.test/hook']);
    $referral = Referral::factory()->create(['lead_error' => 'The leads webhook answered 500.']);

    $this->actingAs($this->admin)->post(route('admin.referrals.resend-lead', $referral))->assertRedirect();

    Queue::assertPushed(SendReferralToLeads::class, fn (SendReferralToLeads $job) => $job->referralId === $referral->id);
    expect($referral->fresh()->lead_error)->toBeNull();
});

test('a referral already in the leads is not sent twice', function () {
    Queue::fake();
    config(['services.leads.webhook_url' => 'https://leads.example.test/hook']);
    $referral = Referral::factory()->create(['lead_sent_at' => now()]);

    $this->actingAs($this->admin)->post(route('admin.referrals.resend-lead', $referral))->assertRedirect();

    Queue::assertNothingPushed();
});
