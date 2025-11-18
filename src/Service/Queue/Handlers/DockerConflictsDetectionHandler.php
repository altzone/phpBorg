<?php

declare(strict_types=1);

namespace PhpBorg\Service\Queue\Handlers;

use PhpBorg\Entity\Job;
use PhpBorg\Service\Queue\Handlers\JobHandlerInterface;
use PhpBorg\Service\Queue\JobQueue;
use PhpBorg\Service\Docker\DockerRestoreService;
use PhpBorg\Logger\LoggerInterface;

/**
 * Handler for Docker conflicts detection jobs
 * Detects containers that conflict with restore operation
 */
final class DockerConflictsDetectionHandler implements JobHandlerInterface
{
    public function __construct(
        private readonly DockerRestoreService $restoreService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(Job $job, JobQueue $queue): string
    {
        $serverId = $job->payload['server_id'] ?? null;
        $selectedItems = $job->payload['selected_items'] ?? [];

        if (!$serverId) {
            throw new \InvalidArgumentException('Missing server_id in job payload');
        }

        $this->logger->info("Detecting conflicts for Docker restore on server ID: {$serverId}", 'DockerConflicts');

        try {
            // Call the service method (which does SSH calls)
            $conflicts = $this->restoreService->detectConflicts((int)$serverId, $selectedItems);

            // Store result in job result field for retrieval
            $result = json_encode($conflicts);

            $this->logger->info(
                sprintf(
                    "Conflict detection complete: %d conflicts, %d containers to stop",
                    count($conflicts['conflicts'] ?? []),
                    count($conflicts['must_stop'] ?? [])
                ),
                'DockerConflicts'
            );

            return $result;

        } catch (\Exception $e) {
            $this->logger->error("Conflict detection failed: " . $e->getMessage(), 'DockerConflicts');
            throw $e;
        }
    }
}
