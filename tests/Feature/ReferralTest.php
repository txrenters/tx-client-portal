<?php

use App\Enums\ReferralStatus;
use App\Models\ClientAccount;
use App\Models\Referral;
use App\Models\Setting;
use App\Models\User;
use App\Services\Referrals\ReferralProgram;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->owner = User::factory()->create(['name' => 'Olive Owner']);
    $this->account = ClientAccount::factory()->owner()->for($this->owner)->create();
});

test('the refer an owner page shows the reward and the client\'s own referrals', function () {
    Setting::put(ReferralProgram::REWARD_SETTING, 'Get $250 when they sign.');
    Referral::factory()->for($this->owner)->create(['client_account_id' => $this->account->id, 'first_name' => 'Jane', 'last_name' => 'Doe']);
    Referral::factory()->create(['first_name' => 'Not', 'last_name' => 'Mine']);

    $this->actingAs($this->owner)
        ->get(route('owner.referrals.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('client/Referrals')
            ->where('portal', 'owner')
            ->where('reward', 'Get $250 when they sign.')
            ->has('referrals.data', 1)
            ->where('referrals.data.0.name', 'Jane Doe'));
});

test('an owner refers a property owner from the web portal', function () {
    $this->actingAs($this->owner)
        ->from(route('owner.referrals.index'))
        ->post(route('owner.referrals.store'), [
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
            'phone' => '(281) 555-0123',
            'property_address' => '123 Main St, Houston, TX 77007',
        ])
        ->assertRedirect(route('owner.referrals.index'));

    expect(Referral::sole())
        ->referrer_role->toBe('owner')
        ->status->toBe(ReferralStatus::New)
        ->source->toBe('Referral from current customer');
});

test('a resident refers an owner from their own portal', function () {
    $resident = User::factory()->create();
    ClientAccount::factory()->resident()->for($resident)->create();

    $this->actingAs($resident)->get(route('resident.referrals.index'))->assertOk();
    $this->actingAs($resident)
        ->post(route('resident.referrals.store'), [
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
            'phone' => '281-555-0123',
            'property_address' => '123 Main St, Houston, TX 77007',
        ])
        ->assertRedirect();

    expect(Referral::sole()->referrer_role)->toBe('resident');
});
