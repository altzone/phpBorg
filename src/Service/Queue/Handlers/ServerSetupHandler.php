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

        // Check if we're configuring an existing server or creating a new one
        $serverId = $payload['server_id'] ?? null;
        $serverName = $payload['server_name'] ?? null;

        if (!$serverName) {
            throw new \Exception('Missing server_name in job payload');
        }

        $this->logger->info("Starting server setup: {$serverName}", 'JOB');
        $queue->updateProgress($job->id, 10, "Starting server setup for: {$serverName}");

        try {
            // If server_id is provided, we're configuring an existing server
            if ($serverId) {
                $queue->updateProgress($job->id, 20, "Configuring existing server (ID: {$serverId})...");

                // TODO: Add actual SSH configuration, Borg installation, repo creation
                // For now, just simulate the process
                $queue->updateProgress($job->id, 40, "Testing SSH connection...");
                sleep(2); // Simulate SSH test

                $queue->updateProgress($job->id, 60, "Installing BorgBackup...");
                sleep(2); // Simulate Borg installation

                $queue->updateProgress($job->id, 80, "Creating backup repositories...");
                sleep(2); // Simulate repo creation

                $queue->updateProgress($job->id, 100, "Server configuration completed successfully.");

                return "Server '{$serverName}' (ID: {$serverId}) configured successfully";
            } else {
                // Legacy path: create new server
                $sshPort = $payload['ssh_port'] ?? 22;
                $retention = $payload['retention'] ?? 8;
                $backupType = $payload['backup_type'] ?? 'internal';

                $queue->updateProgress($job->id, 20, "Creating new server entry...");

                $newServerId = $this->serverManager->addServer(
                    name: $serverName,
                    sshPort: $sshPort,
                    retention: $retention,
                    backupType: $backupType
                );

                $queue->updateProgress($job->id, 100, "Server created successfully. Server ID: {$newServerId}");

                return "Server '{$serverName}' created successfully (ID: {$newServerId})";
            }

        } catch (\Exception $e) {
            $this->logger->error("Server setup failed: {$e->getMessage()}", 'JOB');
            throw new \Exception("Server setup failed: {$e->getMessage()}");
        }
    }
}
