<?php

use App\Models\Announcement;
use App\Models\User;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->resident = User::factory()->resident()->create();
});

test('a client sees live announcements for their portal on every page', function () {
    Announcement::factory()->create(['title' => 'For everyone']);
    Announcement::factory()->audience('resident')->create(['title' => 'For residents']);
    Announcement::factory()->audience('owner')->create(['title' => 'For owners']);
    Announcement::factory()->ended()->create(['title' => 'Over']);
    Announcement::factory()->scheduled()->create(['title' => 'Not yet']);

    $this->actingAs($this->resident)->get(route('help'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('announcements', 2)
            ->where('announcements.0.title', 'For residents')
            ->where('announcements.1.title', 'For everyone'));
});

test('a closed announcement stays closed, in the app too', function () {
    $announcement = Announcement::factory()->create();

    $this->actingAs($this->resident)->post(route('announcements.dismiss', $announcement))->assertRedirect();

    $this->actingAs($this->resident)->get(route('help'))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('announcements', 0));

    Sanctum::actingAs($this->resident);
    $this->getJson(route('api.v1.announcements.index'))->assertOk()->assertJsonCount(0, 'data');
});

test('the app lists announcements and closes one', function () {
    $announcement = Announcement::factory()->create(['title' => 'Office closed Friday']);
    Sanctum::actingAs($this->resident);

    $this->getJson(route('api.v1.announcements.index'))
        ->assertOk()
        ->assertJsonPath('data.0.title', 'Office closed Friday');

    $this->postJson(route('api.v1.announcements.dismiss', $announcement))->assertNoContent();
    $this->getJson(route('api.v1.announcements.index'))->assertJsonCount(0, 'data');
});

test('the admin side shows no announcement banner', function () {
    config(['services.admin_emails' => ['staff@texasrenters.com']]);
    $admin = User::factory()->owner()->create(['email' => 'staff@texasrenters.com']);
    Announcement::factory()->create();

    $this->actingAs($admin)->get(route('admin.dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('announcements', 0));
});
