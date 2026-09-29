<?php

use App\Http\Controllers\Admin\ImpersonationController;
use App\Models\ClientAccount;
use App\Models\Promotion;
use App\Models\PromotionView;
use App\Models\User;

beforeEach(function () {
    Promotion::query()->delete();
    $this->client = User::factory()->create();
    ClientAccount::factory()->resident()->for($this->client)->create();
    $this->promotion = Promotion::factory()->create();
});

test('the web portal pops a promotion up once, then not again that day', function () {
    $this->actingAs($this->client)->getJson(route('promotion.show'))
        ->assertOk()->assertJsonPath('data.id', $this->promotion->id);

    $this->actingAs($this->client)->postJson(route('promotions.record', [$this->promotion, 'seen']))->assertNoContent();

    $this->actingAs($this->client)->getJson(route('promotion.show'))->assertJsonPath('data', null);
});

test('staff signed in as a client never see it or use up the client\'s day', function () {
    $session = [ImpersonationController::SESSION_KEY => 1];

    $this->actingAs($this->client)->withSession($session)->getJson(route('promotion.show'))->assertJsonPath('data', null);
    $this->actingAs($this->client)->withSession($session)->postJson(route('promotions.record', [$this->promotion, 'seen']))->assertNoContent();

    expect(PromotionView::count())->toBe(0);
});

test('rejects anything but seen, click and hide', function () {
    $this->actingAs($this->client)->postJson('/promotions/'.$this->promotion->id.'/delete')->assertNotFound();
});
