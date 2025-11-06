<?php

declare(strict_types=1);

namespace PhpBorg\Service\Queue\Handlers;

use PhpBorg\Entity\Job;
use PhpBorg\Logger\LoggerInterface;
use PhpBorg\Service\Backup\BackupService;
use PhpBorg\Service\Queue\JobQueue;

/**
 * Handler for backup creation jobs
 */
final class BackupCreateHandler implements JobHandlerInterface
{
    public function __construct(
        private readonly BackupService $backupService,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Handle backup creation job
     */
    public function handle(Job $job, JobQueue $queue): string
    {
        $payload = $job->payload;

        // Validate payload
        if (!isset($payload['server_id'])) {
            throw new \Exception('Missing server_id in job payload');
        }

        $serverId = (int) $payload['server_id'];

        $this->logger->info("Starting backup for server ID: {$serverId}", 'JOB');
        $queue->updateProgress($job->id, 10, "Preparing backup for server #{$serverId}...");

        try {
            // Execute backup
            $queue->updateProgress($job->id, 30, "Creating backup...");

            $this->backupService->createBackup($serverId);

            $queue->updateProgress($job->id, 100, "Backup completed successfully");

            return "Backup for server #{$serverId} completed successfully";

        } catch (\Exception $e) {
            $this->logger->error("Backup failed: {$e->getMessage()}", 'JOB');
            throw new \Exception("Backup failed: {$e->getMessage()}");
        }
    }
}
