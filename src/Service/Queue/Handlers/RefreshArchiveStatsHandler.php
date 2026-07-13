<?php

declare(strict_types=1);

namespace PhpBorg\Service\Queue\Handlers;

use PhpBorg\Application;
use PhpBorg\Entity\Job;
use PhpBorg\Service\Queue\JobQueue;

/**
 * Bug 29: background job that refreshes/backfills archive size stats from `borg info`.
 * Runs asynchronously because it can be slow on a cold borg cache.
 */
final class RefreshArchiveStatsHandler implements JobHandlerInterface
{
    public function __construct(private readonly Application $app)
    {
    }

    public function handle(Job $job, JobQueue $queue): string
    {
        $payload = $job->payload;
        $serverId = isset($payload['server_id']) ? (int) $payload['server_id'] : null;
        $type = $payload['type'] ?? null;

        $queue->updateProgress($job->id, 5, 'Refreshing archive stats from borg (fast on a hot cache)...');

        $result = $this->app->getBackupService()->refreshArchiveStats($serverId, $type);

        $queue->updateProgress(
            $job->id,
            100,
            "Refreshed {$result['refreshed']} archive(s), {$result['errors']} error(s)"
        );

        return json_encode($result);
    }
}
