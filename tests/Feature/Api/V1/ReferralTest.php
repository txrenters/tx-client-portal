<?php

use App\Enums\ReferralStatus;
use App\Jobs\SendReferralToLeads;
use App\Models\ClientAccount;
use App\Models\Referral;
use App\Models\Setting;
use App\Models\User;
use App\Services\Referrals\ReferralProgram;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->create(['name' => 'Rita Referrer']);
    $this->account = ClientAccount::factory()->resident()->for($this->user)->create(['propertyware_id' => '900']);
    Sanctum::actingAs($this->user);
});

function referredOwnerDetails(array $overrides = []): array
{
    return [
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'email' => 'Jane@Example.com',
        'phone' => '(281) 555-0123',
        'property_address' => '123 Main St, Houston, TX 77007',
        'property_details' => '3 bed, 2 bath, vacant next month',
        ...$overrides,
    ];
}

test('a client refers a property owner, recorded as a referral for staff', function () {
    $this->postJson(route('api.v1.accounts.referrals.store', $this->account), referredOwnerDetails())
        ->assertCreated()
        ->assertJsonPath('data.name', 'Jane Doe')
        ->assertJsonPath('data.status', 'new')
        ->assertJsonPath('data.statusLabel', 'Received');

    $referral = Referral::sole();
    expect($referral->source)->toBe('Referral from current customer')
        ->and($referral->email)->toBe('jane@example.com')
        ->and($referral->referrer_name)->toBe('Rita Referrer')
        ->and($referral->referrer_role)->toBe('resident')
        ->and($referral->clientAccount->is($this->account))->toBeTrue();
});

test('asks for every owner detail but the property notes', function () {
    $this->postJson(route('api.v1.accounts.referrals.store', $this->account), [])
        ->assertJsonValidationErrors(['first_name', 'last_name', 'email', 'phone', 'property_address'])
        ->assertJsonMissingValidationErrors('property_details');

    $this->postJson(route('api.v1.accounts.referrals.store', $this->account), referredOwnerDetails(['phone' => '555']))
        ->assertJsonValidationErrors(['phone' => 'Enter a phone number with its area code.']);

    expect(Referral::count())->toBe(0);
});

test('lists only this account\'s referrals, with the reward staff set', function () {
    Setting::put(ReferralProgram::REWARD_SETTING, 'Get $250 when they sign.');
    Referral::factory()->for($this->user)->create(['client_account_id' => $this->account->id, 'first_name' => 'Mine']);
    Referral::factory()->status(ReferralStatus::Signed)->create(['first_name' => 'Someone else\'s']);

    $this->getJson(route('api.v1.accounts.referrals.index', $this->account))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', fn (string $name) => str_starts_with($name, 'Mine'))
        ->assertJsonMissingPath('data.0.email')
        ->assertJsonPath('meta.reward', 'Get $250 when they sign.');
});

test('shows the default reward until staff write one', function () {
    $this->getJson(route('api.v1.accounts.referrals.index', $this->account))
        ->assertJsonPath('meta.reward', ReferralProgram::DEFAULT_REWARD);
});

test('never reads or writes another client\'s referrals', function () {
    $other = ClientAccount::factory()->owner()->create();

    $this->getJson(route('api.v1.accounts.referrals.index', $other))->assertNotFound();
    $this->postJson(route('api.v1.accounts.referrals.store', $other), referredOwnerDetails())->assertNotFound();

    expect(Referral::count())->toBe(0);
});

test('sends the referral on to the website\'s leads webhook, as a referral from the client', function () {
    config(['services.leads.webhook_url' => 'https://leads.test/webhook']);
    Http::fake(['leads.test/*' => Http::response(['ok' => true])]);

    $this->postJson(route('api.v1.accounts.referrals.store', $this->account), referredOwnerDetails())->assertCreated();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://leads.test/webhook' && $request->data() === [
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'email_address' => 'jane@example.com',
        'phone_number' => '(281) 555-0123',
        'sources' => ['Referral from current customer'],
        'referred_by' => 'Rita Referrer',
        'rental_property_address' => '123 Main St, Houston, TX 77007',
        'rental_property_details' => '3 bed, 2 bath, vacant next month',
    ]);
    expect(Referral::sole())->lead_sent_at->not->toBeNull()->lead_error->toBeNull();
});

test('keeps the referral and retries when the leads webhook refuses it', function () {
    config(['services.leads.webhook_url' => 'https://leads.test/webhook']);
    Http::fake(['leads.test/*' => Http::response('down', 502)]);
    $referral = Referral::factory()->create();

    expect(fn () => (new SendReferralToLeads($referral->id))->handle())->toThrow(RuntimeException::class);

    expect($referral->fresh())->lead_sent_at->toBeNull()->lead_error->toBe('The leads webhook answered 502.');
});

test('sends nothing when no leads webhook is configured', function () {
    Http::fake();

    $this->postJson(route('api.v1.accounts.referrals.store', $this->account), referredOwnerDetails())->assertCreated();

    Http::assertNothingSent();
});
