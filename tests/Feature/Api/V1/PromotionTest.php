<?php

use App\Models\ClientAccount;
use App\Models\Promotion;
use App\Models\Referral;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    // The launch promotion from the migration would otherwise compete with these.
    Promotion::query()->delete();

    $this->user = User::factory()->create();
    $this->account = ClientAccount::factory()->owner()->for($this->user)->create();
    Sanctum::actingAs($this->user);
});

function popup(): mixed
{
    return test()->getJson(route('api.v1.accounts.promotion.show', test()->account))->assertOk()->json('data');
}

function recordPromotion(Promotion $promotion, string $action): void
{
    test()->postJson(route('api.v1.accounts.promotions.record', [test()->account, $promotion, $action]))->assertNoContent();
}

test('pops up once a day, and again the next day', function () {
    $this->travelTo(now('America/Chicago')->setTime(9, 0));
    $promotion = Promotion::factory()->create(['title' => 'Refer a friend', 'button_link' => 'referrals']);

    expect(popup())->toMatchArray(['id' => $promotion->id, 'title' => 'Refer a friend', 'link' => ['type' => 'referrals']]);

    recordPromotion($promotion, 'seen');
    expect(popup())->toBeNull();

    $this->travel(1)->day();
    expect(popup())->toMatchArray(['id' => $promotion->id]);
});

test('"don\'t show again" turns it off for good', function () {
    $promotion = Promotion::factory()->create();

    recordPromotion($promotion, 'seen');
    recordPromotion($promotion, 'hide');
    $this->travel(3)->days();

    expect(popup())->toBeNull();
});

test('a referral promotion stops once the client has referred someone', function () {
    Promotion::factory()->create(['stop_after_referral' => true]);
    expect(popup())->not->toBeNull();

    Referral::factory()->for($this->user)->create(['client_account_id' => $this->account->id]);

    expect(popup())->toBeNull();
});

test('only running promotions for the client\'s portal pop up', function () {
    Promotion::factory()->create(['is_active' => false]);
    Promotion::factory()->create(['ends_at' => now()->subDay()]);
    Promotion::factory()->create(['starts_at' => now()->addDay()]);
    Promotion::factory()->create(['audience' => 'resident']);
    expect(popup())->toBeNull();

    $forOwners = Promotion::factory()->create(['audience' => 'owner', 'button_link' => 'https://texasrenters.com/offer']);
    expect(popup())->toMatchArray(['id' => $forOwners->id, 'link' => ['type' => 'url', 'url' => 'https://texasrenters.com/offer']]);
});

test('the higher priority promotion pops up first, then the next one another day', function () {
    $low = Promotion::factory()->create(['priority' => 0]);
    $high = Promotion::factory()->create(['priority' => 5]);

    expect(popup()['id'])->toBe($high->id);
    recordPromotion($high, 'seen');
    expect(popup()['id'])->toBe($low->id);
});

test('never reads another client\'s account', function () {
    Promotion::factory()->create();
    $other = ClientAccount::factory()->owner()->create();

    $this->getJson(route('api.v1.accounts.promotion.show', $other))->assertNotFound();
});

test('the app is sent the colour and icon staff chose', function () {
    Promotion::factory()->create(['theme' => 'green', 'icon' => 'house']);

    expect(popup())->theme->toBe('green')->icon->toBe('house');
});
