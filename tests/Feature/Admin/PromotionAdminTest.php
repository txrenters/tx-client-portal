<?php

use App\Models\Promotion;
use App\Models\PromotionView;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config(['services.admin_emails' => ['staff@texasrenters.com']]);
    $this->admin = User::factory()->create(['email' => 'staff@texasrenters.com']);
    // What the launch migration added, before each test starts from none.
    $this->launched = Promotion::all();
    Promotion::query()->delete();
});

function promotionFields(array $overrides = []): array
{
    return [
        'title' => 'Refer a friend',
        'body' => 'Get 2 free months.',
        'button_label' => 'Refer an owner',
        'button_link' => 'referrals',
        'audience' => 'all',
        'is_active' => true,
        'priority' => 0,
        'stop_after_referral' => true,
        ...$overrides,
    ];
}

test('the launch migration adds the referral promotion, running', function () {
    expect($this->launched)->toHaveCount(1)
        ->and($this->launched->first())
        ->button_link->toBe('referrals')
        ->is_active->toBeTrue()
        ->stop_after_referral->toBeTrue();
});

test('staff create a promotion and see how it has done', function () {
    $this->actingAs($this->admin)->post(route('admin.promotions.store'), promotionFields())->assertRedirect();
    $promotion = Promotion::sole();
    PromotionView::create(['promotion_id' => $promotion->id, 'user_id' => User::factory()->create()->id, 'times_shown' => 1, 'clicked_at' => now()]);
    PromotionView::create(['promotion_id' => $promotion->id, 'user_id' => User::factory()->create()->id, 'times_shown' => 2, 'hidden_at' => now()]);

    $this->actingAs($this->admin)->get(route('admin.promotions.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/Promotions')
            ->where('promotions.0.title', 'Refer a friend')
            ->where('promotions.0.stats', ['shown' => 2, 'clicked' => 1, 'hidden' => 1]));
});

test('a button opens the referral page or an https address, nothing else', function () {
    $this->actingAs($this->admin)->post(route('admin.promotions.store'), promotionFields(['button_link' => 'javascript:alert(1)']))
        ->assertSessionHasErrors('button_link');
    $this->actingAs($this->admin)->post(route('admin.promotions.store'), promotionFields(['button_link' => 'https://texasrenters.com/offer']))
        ->assertSessionHasNoErrors();
});

test('staff turn a promotion off and delete it', function () {
    $promotion = Promotion::factory()->create();

    $this->actingAs($this->admin)->put(route('admin.promotions.update', $promotion), promotionFields(['is_active' => false]))->assertRedirect();
    expect($promotion->fresh()->is_active)->toBeFalse();

    $this->actingAs($this->admin)->delete(route('admin.promotions.destroy', $promotion))->assertRedirect();
    expect(Promotion::count())->toBe(0);
});

test('clients cannot manage promotions', function () {
    $client = User::factory()->create();

    $this->actingAs($client)->get(route('admin.promotions.index'))->assertForbidden();
    $this->actingAs($client)->post(route('admin.promotions.store'), promotionFields())->assertForbidden();
});

test('staff choose a colour and icon, which the popup is sent', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.promotions.store'), promotionFields(['theme' => 'violet', 'icon' => 'party-popper']))
        ->assertRedirect();

    expect(Promotion::sole())->theme->toBe('violet')->icon->toBe('party-popper');

    $this->actingAs($this->admin)->get(route('admin.promotions.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('promotions.0.theme', 'violet')
            ->where('promotions.0.icon', 'party-popper'));
});

test('a promotion saved without a design keeps the brand look', function () {
    $this->actingAs($this->admin)->post(route('admin.promotions.store'), promotionFields())->assertRedirect();

    expect(Promotion::sole())->theme->toBe('blue')->icon->toBe('gift');
});

test('only the offered colours and icons are accepted', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.promotions.store'), promotionFields(['theme' => '#ff00ff', 'icon' => 'skull']))
        ->assertSessionHasErrors(['theme', 'icon']);

    expect(Promotion::count())->toBe(0);
});
