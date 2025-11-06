<?php

declare(strict_types=1);

namespace PhpBorg\Service\Queue\Handlers;

use PhpBorg\Entity\Job;
use PhpBorg\Logger\LoggerInterface;
use PhpBorg\Service\Queue\JobQueue;
use PhpBorg\Service\Server\ServerManager;

/**
 * Handler for server setup jobs
 */
final class ServerSetupHandler implements JobHandlerInterface
{
    public function __construct(
        private readonly ServerManager $serverManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Handle server setup job
     */
    public function handle(Job $job, JobQueue $queue): string
    {
        $payload = $job->payload;

        // Validate payload
        if (!isset($payload['server_name'])) {
            throw new \Exception('Missing server_name in job payload');
        }

        $serverName = $payload['server_name'];
        $sshPort = $payload['ssh_port'] ?? 22;
        $retention = $payload['retention'] ?? 8;
        $backupType = $payload['backup_type'] ?? 'internal';

        $this->logger->info("Starting server setup: {$serverName}", 'JOB');
        $queue->updateProgress($job->id, 10, "Starting server setup for: {$serverName}");

        // Execute full server setup (this is the long-running operation)
        try {
            $queue->updateProgress($job->id, 20, "Testing SSH connection...");

            $serverId = $this->serverManager->addServer(
                name: $serverName,
                sshPort: $sshPort,
                retention: $retention,
                backupType: $backupType
            );

            $queue->updateProgress($job->id, 100, "Server setup completed successfully. Server ID: {$serverId}");

            return "Server '{$serverName}' setup completed successfully (ID: {$serverId})";

        } catch (\Exception $e) {
            $this->logger->error("Server setup failed: {$e->getMessage()}", 'JOB');
            throw new \Exception("Server setup failed: {$e->getMessage()}");
        }
    }
}
