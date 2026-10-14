<?php

use App\Enums\HelpRequestStatus;
use App\Models\AdminAction;
use App\Models\HelpRequest;
use App\Models\HelpRequestAttachment;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config(['services.admin_emails' => ['staff@texasrenters.com']]);
    $this->admin = User::factory()->create(['email' => 'staff@texasrenters.com']);
});

test('the inbox opens on what still needs doing', function () {
    HelpRequest::factory()->create(['message' => 'The app shows a blank screen after login']);
    HelpRequest::factory()->resolved()->create();

    $this->actingAs($this->admin)->get(route('admin.help-requests.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/HelpRequests')
            ->where('status', 'open')
            ->where('openCount', 1)
            ->has('requests.data', 1)
            ->where('requests.data.0.message', 'The app shows a blank screen after login'));

    $this->actingAs($this->admin)->get(route('admin.help-requests.index', ['status' => 'all']))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('requests.data', 2));
});

test('staff find a request by who sent it, in any case', function () {
    $dana = User::factory()->create(['email' => 'dana@example.com']);
    HelpRequest::factory()->for($dana)->create();
    HelpRequest::factory()->create();

    $this->actingAs($this->admin)->get(route('admin.help-requests.index', ['search' => 'DANA@']))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('requests.data', 1)->where('requests.data.0.user.email', 'dana@example.com'));
});

test('resolving a request records who did it and gives the client the note', function () {
    $request = HelpRequest::factory()->create();

    $this->actingAs($this->admin)
        ->patch(route('admin.help-requests.update', $request), ['status' => 'resolved', 'staff_note' => 'Reset your link; try again.'])
        ->assertRedirect();

    expect($request->fresh())
        ->status->toBe(HelpRequestStatus::Resolved)
        ->staff_note->toBe('Reset your link; try again.')
        ->resolved_by->toBe($this->admin->id)
        ->and(AdminAction::sole()->action)->toBe('help_request_resolved');
});

test('a resolved request can be opened again', function () {
    $request = HelpRequest::factory()->resolved()->create();

    $this->actingAs($this->admin)->patch(route('admin.help-requests.update', $request), ['status' => 'open'])->assertRedirect();

    expect($request->fresh())->status->toBe(HelpRequestStatus::Open)->resolved_at->toBeNull();
});

test('clients cannot see or settle help requests', function () {
    $client = User::factory()->create();
    $request = HelpRequest::factory()->create();

    $this->actingAs($client)->get(route('admin.help-requests.index'))->assertForbidden();
    $this->actingAs($client)->patch(route('admin.help-requests.update', $request), ['status' => 'resolved'])->assertForbidden();

    expect($request->fresh()->status)->toBe(HelpRequestStatus::Open);
});

test('staff see and open the files a client attached', function () {
    Storage::fake('local');
    $attachment = HelpRequestAttachment::factory()->create(['path' => 'help-requests/9/shot.png']);
    Storage::disk('local')->put('help-requests/9/shot.png', 'png-bytes');

    $this->actingAs($this->admin)->get(route('admin.help-requests.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('requests.data.0.attachments.0.url', route('admin.help-requests.attachments.show', $attachment)));

    $this->actingAs($this->admin)->get(route('admin.help-requests.attachments.show', $attachment))->assertOk();

    $this->actingAs(User::factory()->create())->get(route('admin.help-requests.attachments.show', $attachment))->assertForbidden();
});
