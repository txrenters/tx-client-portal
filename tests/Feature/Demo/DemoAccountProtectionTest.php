<?php

use App\Enums\ClientRole;
use App\Models\Referral;
use App\Models\User;
use App\Services\Referrals\ReferralProgram;
use Laravel\Sanctum\Sanctum;

test('a demo login cannot change its email in the app or on the web', function () {
    [$user] = demoLogin(ClientRole::Owner);

    Sanctum::actingAs($user);
    $this->patchJson(route('api.v1.profile.update'), ['name' => 'Demo Owner', 'email' => 'other@example.com'])
        ->assertJsonValidationErrors(['email' => 'The demo account\'s email can\'t be changed.']);

    $this->actingAs($user)->patch(route('profile.update'), ['name' => 'Demo Owner', 'email' => 'other@example.com'])
        ->assertSessionHasErrors('email');

    expect($user->fresh()->email)->toBe('appreview.owner@texasrenters.com');
});

test('a demo login can still change its name', function () {
    [$user] = demoLogin(ClientRole::Owner);
    Sanctum::actingAs($user);

    $this->patchJson(route('api.v1.profile.update'), ['name' => 'Apple Reviewer', 'email' => $user->email])->assertOk();
});

test('a demo login can delete itself, as App Review checks', function () {
    [$user] = demoLogin(ClientRole::Resident);
    Sanctum::actingAs($user);

    $this->deleteJson(route('api.v1.profile.destroy'), ['password' => 'password'])->assertSuccessful();

    expect($user->fresh())->toBeNull();
});

test('a real login can still change its email', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->patchJson(route('api.v1.profile.update'), ['name' => $user->name, 'email' => 'new@example.com'])->assertOk();
});

test('a demo login that deletes itself takes its referrals with it', function () {
    [$user, $account] = demoLogin(ClientRole::Owner);
    Sanctum::actingAs($user);

    $this->postJson(route('api.v1.accounts.referrals.store', $account), [
        'first_name' => 'Pat', 'last_name' => 'Example', 'email' => 'pat@example.com', 'phone' => '281-555-0199', 'property_address' => '1 Example St',
    ])->assertSuccessful();
    expect(Referral::query()->count())->toBe(1);

    $this->deleteJson(route('api.v1.profile.destroy'), ['password' => 'password'])->assertSuccessful();

    expect(Referral::query()->count())->toBe(0)
        ->and(app(ReferralProgram::class)->forStaff()->total())->toBe(0);
});

test('a real login\'s referral outlives its account, unowned', function () {
    $user = User::factory()->create();
    $referral = Referral::factory()->create(['user_id' => $user->id]);
    Sanctum::actingAs($user);

    $this->deleteJson(route('api.v1.profile.destroy'), ['password' => 'password'])->assertSuccessful();

    expect($referral->fresh())->not->toBeNull()
        ->and($referral->fresh()->user_id)->toBeNull();
});
