<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Queued work that gave up, for staff to retry once the cause is fixed or to
 * discard when it no longer matters.
 *
 * Retrying and forgetting go through the same Artisan commands a developer
 * would run, so the payload is pushed back exactly as Laravel expects.
 */
class FailedJobs
{
    /**
     * The newest failures, with the job's name and the first line of why.
     *
     * @return array<int, array{uuid: string, job: string, queue: string, failedAt: string, error: string}>
     */
    public function recent(int $limit = 50): array
    {
        return DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['uuid', 'queue', 'payload', 'exception', 'failed_at'])
            ->map(fn (object $job): array => [
                'uuid' => (string) $job->uuid,
                'job' => $this->jobName((string) $job->payload),
                'queue' => (string) $job->queue,
                'failedAt' => (string) $job->failed_at,
                'error' => Str::limit(strtok((string) $job->exception, "\n") ?: '', 300),
            ])
            ->all();
    }

    /**
     * The job's class without its namespace, which is all staff need to see.
     */
    public function jobName(string $payload): string
    {
        $name = json_decode($payload, true)['displayName'] ?? 'Unknown job';

        return class_basename((string) $name);
    }

    /**
     * Whether a failed job with this id is on file.
     */
    public function exists(string $uuid): bool
    {
        return DB::table('failed_jobs')->where('uuid', $uuid)->exists();
    }

    /**
     * Put one failed job back on its queue.
     */
    public function retry(string $uuid): void
    {
        Artisan::call('queue:retry', ['id' => [$uuid]]);
    }

    /**
     * Drop one failed job for good.
     */
    public function forget(string $uuid): void
    {
        Artisan::call('queue:forget', ['id' => $uuid]);
    }
}
