<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config(['services.admin_emails' => ['staff@texasrenters.com']]);
    $this->admin = User::factory()->create(['email' => 'staff@texasrenters.com']);

    DB::table('failed_jobs')->insert([
        'uuid' => 'job-1',
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\SendReferralToLeads', 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'data' => []]),
        'exception' => "RuntimeException: The leads webhook answered 500.\n#0 stack",
        'failed_at' => now(),
    ]);
});

test('failed jobs are listed by name with the first line of the error', function () {
    $this->actingAs($this->admin)->get(route('admin.integrations.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('failedJobs.0.uuid', 'job-1')
            ->where('failedJobs.0.job', 'SendReferralToLeads')
            ->where('failedJobs.0.error', 'RuntimeException: The leads webhook answered 500.'));
});

test('a failed job can be retried, which puts it back on the queue', function () {
    $this->actingAs($this->admin)->post(route('admin.integrations.failed-jobs.retry', 'job-1'))->assertRedirect();

    expect(DB::table('failed_jobs')->count())->toBe(0);
});

test('a failed job can be discarded', function () {
    $this->actingAs($this->admin)->delete(route('admin.integrations.failed-jobs.forget', 'job-1'))->assertRedirect();

    expect(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
});

test('an unknown failed job is not found', function () {
    $this->actingAs($this->admin)->post(route('admin.integrations.failed-jobs.retry', 'nope'))->assertNotFound();
});

test('clients cannot retry or discard failed jobs', function () {
    $client = User::factory()->create();

    $this->actingAs($client)->post(route('admin.integrations.failed-jobs.retry', 'job-1'))->assertForbidden();
    $this->actingAs($client)->delete(route('admin.integrations.failed-jobs.forget', 'job-1'))->assertForbidden();

    expect(DB::table('failed_jobs')->count())->toBe(1);
});
