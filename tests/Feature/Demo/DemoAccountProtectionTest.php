<?php

use App\Enums\ClientRole;
use App\Models\User;
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

test('a demo login cannot delete itself', function () {
    [$user] = demoLogin(ClientRole::Resident);
    Sanctum::actingAs($user);

    $this->deleteJson(route('api.v1.profile.destroy'), ['password' => 'password'])->assertForbidden();

    expect($user->fresh())->not->toBeNull();
});

test('a real login can still change its email', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->patchJson(route('api.v1.profile.update'), ['name' => $user->name, 'email' => 'new@example.com'])->assertOk();
});
