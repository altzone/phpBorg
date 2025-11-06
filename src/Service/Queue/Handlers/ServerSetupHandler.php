<?php

declare(strict_types=1);

namespace PhpBorg\Service\Queue\Handlers;

use PhpBorg\Config\Configuration;
use PhpBorg\Entity\Job;
use PhpBorg\Logger\LoggerInterface;
use PhpBorg\Repository\BorgRepositoryRepository;
use PhpBorg\Service\Queue\JobQueue;
use PhpBorg\Service\Repository\EncryptionService;
use PhpBorg\Service\Server\ServerManager;

/**
 * Handler for server setup jobs
 */
final class ServerSetupHandler implements JobHandlerInterface
{
    public function __construct(
        private readonly ServerManager $serverManager,
        private readonly BorgRepositoryRepository $repoRepo,
        private readonly EncryptionService $encryption,
        private readonly Configuration $config,
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

                // Check if repository already exists
                $existing = $this->repoRepo->findByServerAndType($serverId, 'backup');
                if ($existing !== null) {
                    $queue->updateProgress($job->id, 100, "Server already configured with repositories.");
                    return "Server '{$serverName}' (ID: {$serverId}) already has backup repository";
                }

                // Create repository directory path
                $repoPath = $this->config->borgBackupPath . '/' . $serverName . '/backup';

                // Generate passphrase for repository
                $passphrase = $this->encryption->generatePassphrase();

                $queue->updateProgress($job->id, 50, "Creating backup repository configuration...");

                // Create repository entry in database
                // Note: This creates a DB entry but doesn't initialize the actual Borg repo
                // For full setup, SSH connection and borg init would be needed
                $this->repoRepo->create(
                    serverId: $serverId,
                    repoId: 'test-repo-' . $serverId . '-' . time(), // Temporary ID
                    type: 'backup',
                    retention: 8,
                    encryption: 'repokey',
                    passphrase: $passphrase,
                    repoPath: $repoPath,
                    compression: 'lz4',
                    rateLimit: 0,
                    backupPath: '/',
                    exclude: '/proc,/dev,/sys,/tmp,/run,/var/run,/lost+found'
                );

                $queue->updateProgress($job->id, 100, "Repository configuration created successfully.");

                $this->logger->info("Repository created for server {$serverName}", 'JOB');

                return "Server '{$serverName}' (ID: {$serverId}) repository configured successfully";
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
