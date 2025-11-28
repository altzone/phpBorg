<?php

declare(strict_types=1);

namespace PhpBorg\Service\Queue\Handlers;

use PhpBorg\Config\Configuration;
use PhpBorg\Entity\Job;
use PhpBorg\Logger\LoggerInterface;
use PhpBorg\Logger\UserOperationLogger;
use PhpBorg\Repository\AgentRepository;
use PhpBorg\Repository\ArchiveRepository;
use PhpBorg\Repository\BorgRepositoryRepository;
use PhpBorg\Repository\SettingRepository;
use PhpBorg\Service\Backup\BackupService;
use PhpBorg\Service\Queue\JobQueue;
use PhpBorg\Service\Repository\EncryptionService;
use PhpBorg\Service\Server\ServerManager;
use PhpBorg\Service\Email\BackupNotificationService;

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
        private readonly LoggerInterface $logger,
        private readonly BackupNotificationService $notificationService,
        private readonly UserOperationLogger $userLogger,
        private readonly SettingRepository $settingRepo,
        private readonly AgentRepository $agentRepo,
        private readonly ArchiveRepository $archiveRepo
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

            // USER LOG: Backup started
            $this->userLogger->info('backup_create', "Backup started for server '{$server->name}'", [
                'server_id' => $serverId,
                'server_name' => $server->name,
                'type' => $type,
                'triggered_by' => $triggeredBy,
                'job_id' => $job->id,
                'backup_job_id' => $backupJobId
            ]);

            // Route based on connection mode
            if ($server->connectionMode === 'agent') {
                $this->logger->info("Server uses agent mode, routing backup to agent", 'JOB');
                return $this->handleViaAgent($server, $job, $queue, $type, $repositoryId, $backupJobId);
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
                
                // Ensure the physical repository is initialized (idempotent)
                $this->logger->info("Ensuring repository is initialized: {$repository->repoPath}", 'JOB');
                $this->ensureRepositoryInitialized($repository);
            } else {
                // Auto-find or create repository (original behavior)
                $repository = $this->ensureRepositoryExists($serverId, $server->name, $type);
                $this->logger->info("Using repository ID: {$repository->repoId} for {$type} backup", 'JOB');
            }

            // Step 2: Execute backup
            $queue->updateProgress($job->id, 50, "Executing {$type} backup: {$server->name}...");

            // Progress callback for real-time updates in Redis
            $progressCallback = function (array $progressData) use ($queue, $job) {
                $queue->setProgressInfo($job->id, $progressData);
            };

            // If we have a specific repository, use it; otherwise use the original method
            if ($repositoryId) {
                $result = $this->backupService->executeBackupWithRepository($server, $repository, null, $progressCallback);
            } else {
                $result = $this->backupService->executeBackup($server, $type);
            }

            if (!$result['success']) {
                throw new \Exception($result['error'] ?? 'Backup execution failed');
            }

            $archiveName = $result['archiveName'] ?? 'unknown';
            $queue->updateProgress($job->id, 100, "Backup completed: {$archiveName}");

            // Clear real-time progress from Redis (job is complete)
            $queue->deleteProgressInfo($job->id);

            // USER LOG: Backup completed successfully
            $stats = $result['stats'] ?? [];
            $this->userLogger->info('backup_create', "Backup completed successfully: {$archiveName}", [
                'server_name' => $server->name,
                'archive_name' => $archiveName,
                'type' => $type,
                'duration' => $stats['duration'] ?? null,
                'original_size' => $stats['original_size'] ?? null,
                'compressed_size' => $stats['compressed_size'] ?? null,
                'deduplicated_size' => $stats['deduplicated_size'] ?? null,
                'job_id' => $job->id
            ]);

            // Send success notification if backup job ID is provided
            if ($backupJobId) {
                try {
                    $stats = $result['stats'] ?? [];
                    $this->notificationService->sendSuccessNotification(
                        $backupJobId,
                        $server->name,
                        $archiveName,
                        $stats
                    );
                } catch (\Exception $notifEx) {
                    // Log but don't fail the backup if notification fails
                    $this->logger->error("Failed to send success notification: {$notifEx->getMessage()}", 'JOB');
                }
            }

            return "Backup '{$archiveName}' for server '{$server->name}' completed successfully";

        } catch (\Exception $e) {
            $this->logger->error("Backup failed: {$e->getMessage()}", 'JOB');

            // USER LOG: Backup failed
            $this->userLogger->error('backup_create', "Backup failed: {$e->getMessage()}", [
                'server_name' => $server->name ?? 'Unknown',
                'type' => $type,
                'error' => $e->getMessage(),
                'job_id' => $job->id
            ]);

            // Send failure notification if backup job ID is provided
            if ($backupJobId) {
                try {
                    $this->notificationService->sendFailureNotification(
                        $backupJobId,
                        $server->name ?? 'Unknown Server',
                        $e->getMessage()
                    );
                } catch (\Exception $notifEx) {
                    // Log but don't fail the backup if notification fails
                    $this->logger->error("Failed to send failure notification: {$notifEx->getMessage()}", 'JOB');
                }
            }

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

    /**
     * Ensure a repository is physically initialized (borg init)
     * Used when we have a repository in database but need to make sure it's initialized on disk
     * Note: Directory permissions are handled by agent-worker via RefreshAgentAuthorizedKeysHandler
     */
    private function ensureRepositoryInitialized(\PhpBorg\Entity\BorgRepository $repository): void
    {
        // Create repository directory if it doesn't exist (basic mkdir without sudo)
        $repoDir = dirname($repository->repoPath);
        if (!is_dir($repoDir)) {
            $this->logger->info("Creating repository directory: {$repoDir}", 'JOB');
            if (!mkdir($repoDir, 0770, true)) {
                // Directory will be created with proper perms by agent-worker
                $this->logger->info("Directory will be created by agent-worker", 'JOB');
            }
        }

        // Initialize the Borg repository (idempotent - won't fail if already exists)
        $this->logger->info("Initializing Borg repository: {$repository->repoPath}", 'JOB');
        $this->initializeBorgRepository($repository->repoPath, $repository->passphrase, $repository->encryption);
    }

    /**
     * Handle backup via agent
     * Creates a task for the agent and waits for the result
     */
    private function handleViaAgent(
        \PhpBorg\Entity\Server $server,
        Job $job,
        JobQueue $queue,
        string $type,
        ?int $repositoryId,
        ?int $backupJobId
    ): string {
        if (!$server->agentUuid) {
            throw new \Exception("Server {$server->name} is in agent mode but has no agent UUID");
        }

        $queue->updateProgress($job->id, 15, "Preparing backup via agent...");

        // Get or create repository
        if ($repositoryId) {
            $repository = $this->repoRepo->findById($repositoryId);
            if (!$repository) {
                throw new \Exception("Repository #{$repositoryId} not found");
            }
        } else {
            $repository = $this->ensureRepositoryExists($server->id, $server->name, $type);
        }

        // Update agent's allowed paths, prepare directory, and init borg repo
        // This creates a job for the agent-worker (runs as phpborg, has sudo) to:
        // 1. Create/fix repo directory permissions (owned by phpborg-borg)
        // 2. Initialize borg repository as phpborg-borg user
        // 3. Refresh authorized_keys with all paths
        $this->updateAgentAllowedPaths($server->agentUuid, $repository, $queue);

        $queue->updateProgress($job->id, 20, "Sending backup task to agent...");

        // Get database connection
        $app = new \PhpBorg\Application();
        $connection = $app->getConnection();

        // Get agent_id from agents table using uuid
        $agent = $connection->fetchOne(
            'SELECT id FROM agents WHERE uuid = ?',
            [$server->agentUuid]
        );

        if (!$agent) {
            throw new \Exception("Agent not found for UUID: {$server->agentUuid}");
        }

        $agentId = (int)$agent['id'];

        // Build repo URL for borg (agent connects to phpBorg server)
        // Get SSH port and server IPs from database settings
        $borgSshPortSetting = $this->settingRepo->findByKey('borg_ssh_port');
        $borgSshPort = $borgSshPortSetting ? (int)$borgSshPortSetting->value : 2222;

        $borgServerIp = '';
        if ($server->backupType === 'external') {
            $externalIpSetting = $this->settingRepo->findByKey('network.external_ip');
            $borgServerIp = $externalIpSetting ? $externalIpSetting->value : '';
        } else {
            $internalIpSetting = $this->settingRepo->findByKey('network.internal_ip');
            $borgServerIp = $internalIpSetting ? $internalIpSetting->value : '';
        }

        if (empty($borgServerIp)) {
            throw new \Exception("Network IP not configured in settings (network." . ($server->backupType === 'external' ? 'external' : 'internal') . "_ip)");
        }

        // Repo path: ssh://phpborg-borg@server:port/path
        $repoUrl = sprintf(
            'ssh://phpborg-borg@%s:%d%s',
            $borgServerIp,
            $borgSshPort,
            $repository->repoPath
        );

        // Generate archive name
        $archiveName = $type . '_' . date('Y-m-d_H-i-s');

        // Get backup paths
        $backupPaths = $repository->getBackupPaths();
        if (empty($backupPaths)) {
            $backupPaths = ['/'];
        }

        // Get excludes
        $excludes = $repository->exclude ? explode(',', $repository->exclude) : [];

        // Build task payload
        $taskPayload = [
            'repo_path' => $repoUrl,
            'archive_name' => $archiveName,
            'paths' => $backupPaths,
            'excludes' => $excludes,
            'compression' => $repository->compression ?? 'lz4',
            'passphrase' => $repository->passphrase,
            'server_id' => $server->id,
            'repository_id' => $repository->id,
            'backup_job_id' => $backupJobId,
        ];

        // Insert task into agent_tasks table
        $connection->executeUpdate(
            'INSERT INTO agent_tasks (agent_id, job_id, type, payload, status, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())',
            [
                $agentId,
                $job->id,
                'backup_create',
                json_encode($taskPayload),
                'pending'
            ]
        );

        $taskId = $connection->getLastInsertId();
        $this->logger->info("Created agent task #{$taskId} for backup", 'JOB');

        $queue->updateProgress($job->id, 30, "Waiting for agent to execute backup...");

        // Poll for task completion (max 12 hours for large backups)
        $maxWait = 43200;
        $waited = 0;
        $pollInterval = 5;

        while ($waited < $maxWait) {
            sleep($pollInterval);
            $waited += $pollInterval;

            // Check task status
            $task = $connection->fetchOne(
                'SELECT status, result, error FROM agent_tasks WHERE id = ?',
                [$taskId]
            );

            if (!$task) {
                throw new \Exception("Agent task #{$taskId} disappeared");
            }

            if ($task['status'] === 'completed') {
                $result = json_decode($task['result'] ?? '{}', true);

                $queue->updateProgress($job->id, 95, "Backup completed, saving archive info...");

                // Save archive to database by running borg info on the local repository
                try {
                    $archiveStats = $this->saveAgentBackupArchive($repository, $archiveName);
                    $this->logger->info("Archive saved to database: {$archiveName}", 'JOB');
                } catch (\Exception $e) {
                    $this->logger->error("Failed to save archive info: {$e->getMessage()}", 'JOB');
                    // Don't fail the job, backup was successful
                }

                $queue->updateProgress($job->id, 100, "Backup completed via agent");

                // USER LOG: Backup completed
                $this->userLogger->info('backup_create', "Backup completed via agent: {$archiveName}", [
                    'server_name' => $server->name,
                    'archive_name' => $archiveName,
                    'type' => $type,
                    'duration' => $result['duration'] ?? null,
                    'original_size' => $archiveStats['original_size'] ?? null,
                    'compressed_size' => $archiveStats['compressed_size'] ?? null,
                    'deduplicated_size' => $archiveStats['deduplicated_size'] ?? null,
                    'job_id' => $job->id
                ]);

                // Send success notification
                if ($backupJobId) {
                    try {
                        $stats = array_merge($result, $archiveStats ?? []);
                        $this->notificationService->sendSuccessNotification(
                            $backupJobId,
                            $server->name,
                            $archiveName,
                            $stats
                        );
                    } catch (\Exception $notifEx) {
                        $this->logger->error("Failed to send notification: {$notifEx->getMessage()}", 'JOB');
                    }
                }

                return "Backup '{$archiveName}' for server '{$server->name}' completed via agent";
            }

            if ($task['status'] === 'failed') {
                $error = $task['error'] ?? 'Unknown error';
                throw new \Exception("Agent backup failed: {$error}");
            }

            // Update progress periodically
            $progress = min(30 + ($waited / $maxWait * 60), 90);
            $queue->updateProgress($job->id, (int)$progress, "Backup in progress via agent ({$waited}s)...");
        }

        throw new \Exception("Agent backup timed out after {$maxWait} seconds");
    }

    /**
     * Update agent's allowed paths, prepare directory, init borg, and refresh authorized_keys
     *
     * Creates a job for the agent-worker (has sudo) to:
     * 1. Create/fix repo directory permissions (owned by phpborg-borg)
     * 2. Initialize borg repository as phpborg-borg user
     * 3. Refresh authorized_keys with all paths
     */
    private function updateAgentAllowedPaths(string $agentUuid, \PhpBorg\Entity\BorgRepository $repository, JobQueue $queue): void
    {
        $agent = $this->agentRepo->findByUuid($agentUuid);
        if (!$agent) {
            $this->logger->warning("Agent not found for UUID: {$agentUuid}, skipping allowed_paths update", 'JOB');
            return;
        }

        $repoPath = $repository->repoPath;
        $agentId = (int)$agent['id'];
        $currentPaths = $this->agentRepo->getAllowedPaths($agentId);

        // Add path if not already in database
        if (!in_array($repoPath, $currentPaths, true)) {
            $this->agentRepo->addAllowedPath($agentId, $repoPath);
            $this->logger->info("Added allowed path for agent {$agentUuid}: {$repoPath}", 'JOB');
        }

        // Get updated paths
        $updatedPaths = $this->agentRepo->getAllowedPaths($agentId);

        // Create a job for agent-worker to:
        // 1. Prepare repository directory with correct permissions
        // 2. Initialize borg repository as phpborg-borg
        // 3. Refresh authorized_keys with all paths
        $refreshJobId = $queue->push('refresh_agent_authorized_keys', [
            'agent_uuid' => $agentUuid,
            'agent_name' => $agent['name'],
            'public_key' => $agent['ssh_public_key'],
            'allowed_paths' => $updatedPaths,
            'append_only' => (bool)$agent['append_only'],
            'repo_path' => $repoPath,
            'passphrase' => $repository->passphrase,
            'encryption' => $repository->encryption,
        ], 'agent');

        $this->logger->info("Created refresh_agent_authorized_keys job #{$refreshJobId} for agent {$agentUuid}", 'JOB');

        // Wait for the job to complete (max 30 seconds)
        $maxWait = 30;
        $waited = 0;
        $pollInterval = 1;

        while ($waited < $maxWait) {
            sleep($pollInterval);
            $waited += $pollInterval;

            $refreshJob = $queue->getJob($refreshJobId);
            if (!$refreshJob) {
                throw new \RuntimeException("Refresh job #{$refreshJobId} disappeared");
            }

            if ($refreshJob->status === 'completed') {
                $this->logger->info("Authorized_keys refreshed for agent {$agentUuid} with " . count($updatedPaths) . " paths", 'JOB');
                return;
            }

            if ($refreshJob->status === 'failed') {
                throw new \RuntimeException("Failed to refresh authorized_keys: " . ($refreshJob->error ?? 'Unknown error'));
            }
        }

        throw new \RuntimeException("Timeout waiting for authorized_keys refresh job");
    }

    /**
     * Save archive info to database after agent backup completes
     * Runs borg info on the local repository to get archive stats
     */
    private function saveAgentBackupArchive(\PhpBorg\Entity\BorgRepository $repository, string $archiveName): array
    {
        $repoPath = $repository->repoPath;
        $fullArchivePath = $repoPath . '::' . $archiveName;

        // Run borg info to get archive details
        $command = sprintf(
            'BORG_PASSPHRASE=%s %s info --json %s 2>&1',
            escapeshellarg($repository->passphrase),
            escapeshellarg($this->config->borgBinaryPath),
            escapeshellarg($fullArchivePath)
        );

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        $outputStr = implode("\n", $output);

        if ($returnCode !== 0) {
            throw new \Exception("borg info failed: {$outputStr}");
        }

        $archiveInfo = json_decode($outputStr, true);
        if (!$archiveInfo) {
            throw new \Exception("Failed to parse borg info output");
        }

        // Extract archive data
        $archiveData = null;
        if (isset($archiveInfo['archives']) && !empty($archiveInfo['archives'])) {
            $archiveData = $archiveInfo['archives'][0];
        } elseif (isset($archiveInfo['archive'])) {
            $archiveData = $archiveInfo['archive'];
        }

        if (!$archiveData) {
            throw new \Exception("No archive data in borg info output");
        }

        $stats = $archiveData['stats'] ?? [];
        $archiveId = $archiveData['id'] ?? '';

        if (empty($archiveId)) {
            throw new \Exception("Archive ID is empty in borg info output");
        }

        // Check if archive already exists
        $existing = $this->archiveRepo->findByArchiveId($archiveId);
        if ($existing) {
            $this->logger->info("Archive {$archiveId} already exists in database", 'JOB');
            return $stats;
        }

        // Calculate transfer rate
        $duration = (float)($archiveData['duration'] ?? 0);
        $originalSize = (int)($stats['original_size'] ?? 0);
        $avgTransferRate = null;
        if ($duration > 0 && $originalSize > 0) {
            $avgTransferRate = (int)($originalSize / $duration);
        }

        // Save to database
        $this->archiveRepo->create(
            repoId: $repository->repoId,
            serverId: $repository->serverId,
            name: $archiveName,
            archiveId: $archiveId,
            duration: $duration,
            start: new \DateTimeImmutable($archiveData['start'] ?? 'now'),
            end: new \DateTimeImmutable($archiveData['end'] ?? 'now'),
            filesCount: (int)($stats['nfiles'] ?? 0),
            originalSize: $originalSize,
            compressedSize: (int)($stats['compressed_size'] ?? 0),
            deduplicatedSize: (int)($stats['deduplicated_size'] ?? 0),
            avgTransferRate: $avgTransferRate
        );

        return [
            'original_size' => $originalSize,
            'compressed_size' => (int)($stats['compressed_size'] ?? 0),
            'deduplicated_size' => (int)($stats['deduplicated_size'] ?? 0),
            'files_count' => (int)($stats['nfiles'] ?? 0),
            'duration' => $duration,
        ];
    }
}
