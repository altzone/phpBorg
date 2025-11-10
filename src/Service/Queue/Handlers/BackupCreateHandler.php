<?php

declare(strict_types=1);

namespace PhpBorg\Service\Queue\Handlers;

use PhpBorg\Config\Configuration;
use PhpBorg\Entity\Job;
use PhpBorg\Logger\LoggerInterface;
use PhpBorg\Repository\BorgRepositoryRepository;
use PhpBorg\Service\Backup\BackupService;
use PhpBorg\Service\Queue\JobQueue;
use PhpBorg\Service\Repository\EncryptionService;
use PhpBorg\Service\Server\ServerManager;

/**
 * Handler for backup creation jobs
 * Responsibilities:
 * - Check if repository exists for the server and backup type
 * - Create repository if it doesn't exist
 * - Execute the backup
 */
final class BackupCreateHandler implements JobHandlerInterface
{
    public function __construct(
        private readonly BackupService $backupService,
        private readonly ServerManager $serverManager,
        private readonly BorgRepositoryRepository $repoRepo,
        private readonly EncryptionService $encryption,
        private readonly Configuration $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Handle backup creation job
     * Creates repository if needed, then executes backup
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
        $repositoryId = isset($payload['repository_id']) ? (int)$payload['repository_id'] : null;
        $backupJobId = isset($payload['backup_job_id']) ? (int)$payload['backup_job_id'] : null;
        $triggeredBy = $payload['triggered_by'] ?? 'scheduled';

        // Log if this is a manual trigger
        if ($triggeredBy === 'manual') {
            $this->logger->info(
                "Manual backup triggered for job #{$backupJobId} by user '{$payload['triggered_by_user']}' at {$payload['triggered_at']}", 
                'JOB'
            );
        }

        $this->logger->info("Starting {$type} backup for server ID: {$serverId}", 'JOB');
        $queue->updateProgress($job->id, 10, "Preparing {$type} backup for server #{$serverId}...");

        try {
            // Get server object
            $server = $this->serverManager->getServerById($serverId);
            if (!$server) {
                throw new \Exception("Server #{$serverId} not found");
            }

            // Step 1: Get repository - either specified or auto-find/create
            $queue->updateProgress($job->id, 20, "Checking repository configuration for {$type}...");
            
            if ($repositoryId) {
                // Use specified repository (for manual runs from backup jobs)
                $repository = $this->repoRepo->findById($repositoryId);
                if (!$repository) {
                    throw new \Exception("Repository #{$repositoryId} not found");
                }
                $this->logger->info("Using specified repository ID: {$repository->repoId} for {$type} backup", 'JOB');
            } else {
                // Auto-find or create repository (original behavior)
                $repository = $this->ensureRepositoryExists($serverId, $server->name, $type);
                $this->logger->info("Using repository ID: {$repository->repoId} for {$type} backup", 'JOB');
            }

            // Step 2: Execute backup
            $queue->updateProgress($job->id, 50, "Executing {$type} backup: {$server->name}...");

            // If we have a specific repository, use it; otherwise use the original method
            if ($repositoryId) {
                $result = $this->backupService->executeBackupWithRepository($server, $repository);
            } else {
                $result = $this->backupService->executeBackup($server, $type);
            }

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

    /**
     * Ensure a repository exists for the given server and type
     * If it doesn't exist, create it (idempotent)
     *
     * @return \PhpBorg\Entity\BorgRepository
     */
    private function ensureRepositoryExists(int $serverId, string $serverName, string $type): \PhpBorg\Entity\BorgRepository
    {
        // Check if repository already exists in database
        $existing = $this->repoRepo->findByServerAndType($serverId, $type);
        if ($existing !== null) {
            $this->logger->info("Repository already exists for server {$serverName} ({$type})", 'JOB');
            return $existing;
        }

        // Create new repository
        $this->logger->info("Creating new repository for server {$serverName} ({$type})", 'JOB');

        // Determine repository configuration based on backup type
        $config = $this->getRepositoryConfig($type);

        // Generate secure passphrase
        $passphrase = $this->encryption->generatePassphrase();

        // Create repository path on local backup server
        $repoPath = $this->config->borgBackupPath . '/' . $serverName . '/' . $type;

        // Step 1: Create directory on local backup server
        $this->logger->info("Creating repository directory: {$repoPath}", 'JOB');
        if (!is_dir($repoPath)) {
            if (!mkdir($repoPath, 0700, true)) {
                throw new \Exception("Failed to create repository directory: {$repoPath}");
            }
        }

        // Step 2: Initialize Borg repository locally
        $this->logger->info("Initializing Borg repository with encryption: {$config['encryption']}", 'JOB');
        $this->initializeBorgRepository($repoPath, $passphrase, $config['encryption']);

        // Step 3: Create repository entry in database
        $repoId = $this->repoRepo->create(
            serverId: $serverId,
            repoId: $type . '-repo-' . $serverId . '-' . time(),
            type: $type,
            retention: $config['retention'],
            encryption: $config['encryption'],
            passphrase: $passphrase,
            repoPath: $repoPath,
            compression: $config['compression'],
            rateLimit: $config['rate_limit'],
            backupPath: $config['backup_path'],
            exclude: $config['exclude']
        );

        $this->logger->info("Repository created successfully with ID: {$repoId}", 'JOB');

        // Fetch and return the created repository
        $repository = $this->repoRepo->findById($repoId);
        if (!$repository) {
            throw new \Exception("Failed to retrieve created repository");
        }

        return $repository;
    }

    /**
     * Initialize a Borg repository on the local backup server
     */
    private function initializeBorgRepository(string $repoPath, string $passphrase, string $encryption): void
    {
        $command = sprintf(
            'BORG_PASSPHRASE=%s %s init --encryption=%s %s 2>&1',
            escapeshellarg($passphrase),
            escapeshellarg($this->config->borgBinaryPath),
            escapeshellarg($encryption),
            escapeshellarg($repoPath)
        );

        $this->logger->info("Executing: borg init --encryption={$encryption} {$repoPath}", 'JOB');

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        $outputStr = implode("\n", $output);

        // Borg init returns 0 on success, but also check for "already exists" which means it's already initialized
        if ($returnCode !== 0) {
            // Check if error is "already exists" - this is OK (idempotent)
            if (str_contains($outputStr, 'already exists') || str_contains($outputStr, 'is already initialized')) {
                $this->logger->info("Repository already initialized (idempotent): {$repoPath}", 'JOB');
                return;
            }

            $this->logger->error("Borg init failed: {$outputStr}", 'JOB');
            throw new \Exception("Failed to initialize Borg repository: {$outputStr}");
        }

        $this->logger->info("Borg repository initialized successfully: {$outputStr}", 'JOB');
    }

    /**
     * Get repository configuration based on backup type
     */
    private function getRepositoryConfig(string $type): array
    {
        switch ($type) {
            case 'backup':
                // Full system backup
                return [
                    'retention' => 8,
                    'encryption' => 'repokey',
                    'compression' => 'lz4',
                    'rate_limit' => 0,
                    'backup_path' => '/',
                    'exclude' => '/proc,/dev,/sys,/tmp,/run,/var/run,/var/tmp,/var/cache,/var/lock,/lost+found,/mnt,/media,/swapfile,/swap.img,*.tmp,*.swp,*.lock',
                ];

            case 'database':
                // Database backup
                return [
                    'retention' => 14, // Keep more database backups
                    'encryption' => 'repokey',
                    'compression' => 'zstd', // Better compression for databases
                    'rate_limit' => 0,
                    'backup_path' => '/var/backups/databases',
                    'exclude' => '',
                ];

            default:
                // Default configuration
                return [
                    'retention' => 7,
                    'encryption' => 'repokey',
                    'compression' => 'lz4',
                    'rate_limit' => 0,
                    'backup_path' => '/',
                    'exclude' => '',
                ];
        }
    }
}
