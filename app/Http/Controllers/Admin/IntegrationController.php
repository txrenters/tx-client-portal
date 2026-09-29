<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\FlashesToasts;
use App\Http\Controllers\Controller;
use App\Services\Admin\AdminAudit;
use App\Services\Admin\FailedJobs;
use App\Services\Admin\IntegrationHealth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IntegrationController extends Controller
{
    use FlashesToasts;

    /**
     * Every service the portal depends on, with its current state, and the
     * queued work that gave up.
     */
    public function index(IntegrationHealth $health, FailedJobs $failedJobs): Response
    {
        return Inertia::render('admin/Integrations', [
            'queue' => $health->queue(),
            'failedJobs' => $failedJobs->recent(),
            'integrations' => Inertia::defer(fn (): array => $health->all()),
        ]);
    }

    /**
     * Check the services again now, rather than waiting for the cache to lapse.
     */
    public function refresh(IntegrationHealth $health): RedirectResponse
    {
        $health->all(fresh: true);
        $this->toast('Integrations checked again.');

        return back();
    }

    /**
     * Put a failed job back on its queue, once whatever broke it is fixed.
     */
    public function retryJob(Request $request, string $uuid, FailedJobs $failedJobs, AdminAudit $audit): RedirectResponse
    {
        abort_unless($failedJobs->exists($uuid), 404);

        $failedJobs->retry($uuid);
        $audit->record($request->user(), 'failed_job_retried', "Retried a failed job ({$uuid})");
        $this->toast('The job is queued to run again.');

        return back();
    }

    /**
     * Throw a failed job away for good.
     */
    public function forgetJob(Request $request, string $uuid, FailedJobs $failedJobs, AdminAudit $audit): RedirectResponse
    {
        abort_unless($failedJobs->exists($uuid), 404);

        $failedJobs->forget($uuid);
        $audit->record($request->user(), 'failed_job_discarded', "Discarded a failed job ({$uuid})");
        $this->toast('The failed job was discarded.');

        return back();
    }
}
