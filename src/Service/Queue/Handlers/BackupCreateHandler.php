<?php

declare(strict_types=1);

namespace PhpBorg\Service\Queue\Handlers;

use PhpBorg\Entity\Job;
use PhpBorg\Logger\LoggerInterface;
use PhpBorg\Service\Backup\BackupService;
use PhpBorg\Service\Queue\JobQueue;
use PhpBorg\Service\Server\ServerManager;

/**
 * Handler for backup creation jobs
 */
final class BackupCreateHandler implements JobHandlerInterface
{
    public function __construct(
        private readonly BackupService $backupService,
        private readonly ServerManager $serverManager,
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
        $type = $payload['type'] ?? 'backup';

        $this->logger->info("Starting {$type} backup for server ID: {$serverId}", 'JOB');
        $queue->updateProgress($job->id, 10, "Preparing {$type} backup for server #{$serverId}...");

        try {
            // Get server object
            $server = $this->serverManager->getServerById($serverId);
            if (!$server) {
                throw new \Exception("Server #{$serverId} not found");
            }

            // Execute backup
            $queue->updateProgress($job->id, 30, "Executing backup: {$server->name}...");

            $result = $this->backupService->executeBackup($server, $type);

            if (!$result['success']) {
                throw new \Exception($result['error'] ?? 'Backup execution failed');
            }

            $archiveName = $result['archiveName'] ?? 'unknown';
            $queue->updateProgress($job->id, 100, "Backup completed: {$archiveName}");

            return "Backup '{$archiveName}' for server '{$server->name}' completed successfully";

        } catch (\Exception $e) {
            $this->logger->error("Backup failed: {$e->getMessage()}", 'JOB');
            throw new \Exception("Backup failed: {$e->getMessage()}");
        }
    }
}
