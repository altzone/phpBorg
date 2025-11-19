<?php

declare(strict_types=1);

namespace PhpBorg\Service\Queue\Handlers;

use PhpBorg\Entity\Job;
use PhpBorg\Logger\LoggerInterface;
use PhpBorg\Logger\UserOperationLogger;
use PhpBorg\Repository\RestoreOperationRepository;
use PhpBorg\Repository\ArchiveRepository;
use PhpBorg\Repository\BorgRepositoryRepository;
use PhpBorg\Repository\ServerRepository;
use PhpBorg\Repository\SettingRepository;
use PhpBorg\Service\Queue\JobQueue;
use PhpBorg\Service\Server\SshExecutor;
use PhpBorg\Service\Backup\BorgExecutor;
use PhpBorg\Service\Docker\DockerRestoreService;
use PhpBorg\Exception\RestoreException;

/**
 * Handler for Docker restore operations
 * Responsibilities:
 * - Stop conflicting containers
 * - Create LVM snapshots (protection)
 * - Extract from Borg archive
 * - Adapt paths if needed
 * - Restart containers
 * - Health checks
 * - Set rollback window
 */
final class DockerRestoreHandler implements JobHandlerInterface
{
    public function __construct(
        private readonly RestoreOperationRepository $restoreOperationRepo,
        private readonly ArchiveRepository $archiveRepo,
        private readonly BorgRepositoryRepository $repositoryRepo,
        private readonly ServerRepository $serverRepo,
        private readonly SettingRepository $settingRepo,
        private readonly SshExecutor $sshExecutor,
        private readonly BorgExecutor $borgExecutor,
        private readonly DockerRestoreService $restoreService,
        private readonly LoggerInterface $logger,
        private readonly UserOperationLogger $userLogger
    ) {
    }

    /**
     * Handle Docker restore job
     */
    public function handle(Job $job, JobQueue $queue): string
    {
        $payload = $job->payload;

        $operationId = $payload['operation_id'] ?? null;
        if (!$operationId) {
            throw new \Exception('Missing operation_id in job payload');
        }

        $this->logger->info("Starting Docker restore operation: {$operationId}", 'JOB');
        $queue->updateProgress($job->id, 5, "Loading restore operation...");

        try {
            // Load operation
            $operation = $this->restoreOperationRepo->findById($operationId);
            if (!$operation) {
                throw new RestoreException("Restore operation not found: {$operationId}");
            }

            // Mark as running
            $this->restoreOperationRepo->updateStatus($operationId, 'running');

            // Load related entities
            $archive = $this->archiveRepo->findById($operation->archiveId);
            $repository = $this->repositoryRepo->findByRepoId($archive->repoId);
            $server = $this->serverRepo->findById($operation->serverId);

            $this->logger->info(
                "Restoring archive '{$archive->name}' to server '{$server->name}'",
                $server->name
            );

            // USER LOG: Docker restore started
            $this->userLogger->info('docker_restore', "Docker restore started from archive '{$archive->name}'", [
                'server_name' => $server->name,
                'archive_name' => $archive->name,
                'operation_id' => $operationId,
                'restore_type' => $operation->restoreType,
                'destination' => $operation->destination,
                'job_id' => $job->id
            ]);

            // Step 1: Stop containers (if needed)
            if (!empty($operation->selectedItems['must_stop'])) {
                $queue->updateProgress($job->id, 10, "Stopping containers...");
                $this->stopContainers($server, $operation->selectedItems['must_stop'], $operationId);
            }

            // Step 2: Create LVM snapshot (protection)
            if ($operation->lvmSnapshotCreated || ($payload['create_lvm_snapshot'] ?? false)) {
                $queue->updateProgress($job->id, 20, "Creating LVM snapshot...");
                $this->createLvmSnapshot($server, $payload, $operationId);
            }

            // Step 3: Create pre-restore backup (protection)
            if ($operation->preRestoreBackupCreated || ($payload['create_pre_restore_backup'] ?? false)) {
                $queue->updateProgress($job->id, 30, "Creating pre-restore backup...");
                $this->createPreRestoreBackup($server, $repository, $operation, $operationId);
            }

            // Step 4: Extract from Borg archive
            $queue->updateProgress($job->id, 40, "Extracting from Borg archive...");
            $this->extractFromBorg($server, $repository, $archive, $operation, $job, $queue);

            // Step 5: Adapt paths (if alternative location + compose files)
            if ($operation->destination === 'alternative' && $operation->composePathAdaptation !== 'none') {
                $queue->updateProgress($job->id, 70, "Adapting paths in compose files...");
                $this->adaptComposePaths($server, $operation, $operationId);
            }

            // Step 6: Restart containers
            if ($operation->autoRestart && !empty($operation->stoppedContainers)) {
                $queue->updateProgress($job->id, 80, "Restarting containers...");
                $this->restartContainers($server, $operation->stoppedContainers, $operationId);
            }

            // Step 7: Health checks
            $queue->updateProgress($job->id, 90, "Running health checks...");
            $this->runHealthChecks($server, $operation, $operationId);

            // Step 8: Set rollback window (8 hours)
            $this->restoreOperationRepo->setRollbackWindow($operationId, 8);

            // Mark as completed
            $this->restoreOperationRepo->updateStatus($operationId, 'completed');
            $queue->updateProgress($job->id, 100, "Restore completed successfully!");

            $this->logger->info("Docker restore completed successfully", $server->name);

            // USER LOG: Docker restore completed
            $this->userLogger->info('docker_restore', "Docker restore completed successfully", [
                'server_name' => $server->name,
                'archive_name' => $archive->name,
                'operation_id' => $operationId,
                'rollback_available' => true,
                'rollback_hours' => 8,
                'job_id' => $job->id
            ]);

            return "Docker restore completed successfully. Rollback available for 8 hours.";

        } catch (\Exception $e) {
            $this->logger->error("Docker restore failed: {$e->getMessage()}", 'JOB');

            // Mark as failed
            $this->restoreOperationRepo->updateStatus($operationId, 'failed', $e->getMessage());

            // USER LOG: Docker restore failed
            $this->userLogger->error('docker_restore', "Docker restore failed: {$e->getMessage()}", [
                'server_name' => $server->name ?? 'Unknown',
                'archive_name' => $archive->name ?? 'Unknown',
                'operation_id' => $operationId,
                'error' => $e->getMessage(),
                'job_id' => $job->id
            ]);

            // Auto-rollback on failure
            if ($payload['auto_rollback_on_failure'] ?? true) {
                $this->logger->info("Attempting auto-rollback...", 'JOB');
                try {
                    $this->rollback($operationId);
                    return "Restore failed, auto-rollback executed: " . $e->getMessage();
                } catch (\Exception $rollbackError) {
                    $this->logger->error("Auto-rollback failed: {$rollbackError->getMessage()}", 'JOB');
                }
            }

            throw $e;
        }
    }

    /**
     * Stop Docker containers
     *
     * @param array<string> $containers
     */
    private function stopContainers(object $server, array $containers, int $operationId): void
    {
        $this->logger->info("Stopping " . count($containers) . " container(s)", $server->name);

        $stoppedContainers = [];
        $restartOrder = 0;

        foreach ($containers as $containerName) {
            $result = $this->sshExecutor->execute(
                $server,
                sprintf('docker stop %s', escapeshellarg($containerName)),
                60
            );

            if ($result['exitCode'] === 0) {
                $this->logger->info("Stopped container: {$containerName}", $server->name);
                $stoppedContainers[] = [
                    'name' => $containerName,
                    'restart_order' => $restartOrder++,
                    'stopped_at' => date('Y-m-d H:i:s'),
                ];
            } else {
                $this->logger->warning(
                    "Failed to stop container {$containerName}: {$result['stderr']}",
                    $server->name
                );
            }
        }

        $this->restoreOperationRepo->updateStoppedContainers($operationId, $stoppedContainers);
    }

    /**
     * Create LVM snapshot for rollback capability
     *
     * @param array<string, mixed> $config
     */
    private function createLvmSnapshot(object $server, array $config, int $operationId): void
    {
        $lvmPath = $config['lvm_path'] ?? '/dev/vg_data/lv_docker';
        $snapshotSize = $config['snapshot_size'] ?? '20G';
        $snapshotName = 'restore_snapshot_' . date('Ymd_His');

        $this->logger->info("Creating LVM snapshot: {$snapshotName}", $server->name);

        $result = $this->sshExecutor->execute(
            $server,
            sprintf(
                'lvcreate -L %s -s -n %s %s',
                escapeshellarg($snapshotSize),
                escapeshellarg($snapshotName),
                escapeshellarg($lvmPath)
            ),
            120
        );

        if ($result['exitCode'] !== 0) {
            throw new RestoreException("Failed to create LVM snapshot: {$result['stderr']}");
        }

        $this->logger->info("LVM snapshot created successfully", $server->name);
        $this->restoreOperationRepo->markLvmSnapshotCreated($operationId, $snapshotName);
    }

    /**
     * Create backup of current state before restore
     */
    private function createPreRestoreBackup(
        object $server,
        object $repository,
        object $operation,
        int $operationId
    ): void {
        $archiveName = 'pre_restore_backup_' . date('Ymd_His');
        $this->logger->info("Creating pre-restore backup: {$archiveName}", $server->name);

        // Build paths to backup based on what will be restored
        $paths = [];
        if (!empty($operation->selectedItems['volumes'])) {
            foreach ($operation->selectedItems['volumes'] as $volumeName) {
                $paths[] = "/var/lib/docker/volumes/{$volumeName}";
            }
        }
        if (!empty($operation->selectedItems['projects'])) {
            foreach ($operation->selectedItems['projects'] as $project) {
                if (isset($project['path'])) {
                    $paths[] = $project['path'];
                }
            }
        }

        if (empty($paths)) {
            $this->logger->info("No paths to backup, skipping pre-restore backup", $server->name);
            return;
        }

        $result = $this->borgExecutor->createBackup(
            $repository->repoPath,
            $repository->passphrase,
            $archiveName,
            $paths,
            [],
            7200 // 2 hour timeout
        );

        if ($result['exitCode'] !== 0 && $result['exitCode'] !== 1) {
            throw new RestoreException("Failed to create pre-restore backup: {$result['stderr']}");
        }

        $this->logger->info("Pre-restore backup created successfully", $server->name);
        $this->restoreOperationRepo->markPreRestoreBackupCreated($operationId, $archiveName);
    }

    /**
     * Extract files from Borg archive
     */
    private function extractFromBorg(
        object $server,
        object $repository,
        object $archive,
        object $operation,
        object $job,
        JobQueue $queue
    ): void {
        $this->logger->info("Extracting from Borg archive: {$repository->repoPath}::{$archive->name}", $server->name);

        // Build Borg repository SSH URL (like ArchiveRestoreHandler)
        $borgRepoUrl = $this->getBorgRepoUrl($repository, $server);
        $this->logger->info("🔥🔥🔥 NEW CODE LOADED - Borg repo URL: {$borgRepoUrl}", $server->name);

        // Build borg options (BEFORE archive name, like ArchiveRestoreHandler)
        $borgOptions = ['--progress'];
        if ($operation->destination === 'alternative') {
            $borgOptions[] = '--strip-components=1';
        }
        $optionsStr = implode(' ', $borgOptions);

        // Determine extraction destination
        $extractPath = $operation->destination === 'alternative'
            ? $operation->alternativePath
            : '/';

        // Create destination directory if needed
        if ($operation->destination === 'alternative') {
            $mkdirResult = $this->sshExecutor->execute($server, "mkdir -p " . escapeshellarg($extractPath), 30);
            if ($mkdirResult['exitCode'] !== 0) {
                throw new RestoreException("Failed to create destination directory: {$mkdirResult['stderr']}");
            }
        }

        // Build extraction command with BORG_REPO environment variable
        // Same format as ArchiveRestoreHandler: options BEFORE ::archive
        $cmd = sprintf(
            'export BORG_REPO=%s && export BORG_PASSPHRASE=%s && export BORG_RSH="ssh -i /root/.ssh/phpborg_backup -o StrictHostKeyChecking=no" && cd %s && borg extract %s ::%s',
            escapeshellarg($borgRepoUrl),
            escapeshellarg($repository->passphrase),
            escapeshellarg($extractPath),
            $optionsStr,
            escapeshellarg($archive->name)
        );

        // Add specific paths if custom selection
        if ($operation->restoreType === 'custom' && !empty($operation->selectedItems)) {
            $selectedPaths = [];

            $this->logger->info("DEBUG selected_items: " . json_encode($operation->selectedItems), $server->name);

            // Docker volumes
            if (!empty($operation->selectedItems['volumes'])) {
                foreach ($operation->selectedItems['volumes'] as $volumeName) {
                    $selectedPaths[] = "var/lib/docker/volumes/{$volumeName}";
                }
                $this->logger->info("DEBUG Added " . count($operation->selectedItems['volumes']) . " volumes", $server->name);
            }

            // Docker Compose projects
            if (!empty($operation->selectedItems['projects'])) {
                $this->logger->info("DEBUG Found projects: " . json_encode($operation->selectedItems['projects']), $server->name);
                foreach ($operation->selectedItems['projects'] as $project) {
                    // New format: object with name and path
                    if (is_array($project)) {
                        if (isset($project['path']) && !empty($project['path'])) {
                            // Use actual path from backup metadata
                            $selectedPaths[] = ltrim($project['path'], '/');
                            $this->logger->info("DEBUG Added project path: " . $project['path'], $server->name);
                        } elseif (isset($project['name'])) {
                            // Fallback: use default path pattern for old backups
                            $selectedPaths[] = "var/lib/docker/compose/{$project['name']}";
                            $this->logger->info("DEBUG Added project fallback name: " . $project['name'], $server->name);
                        }
                    }
                    // Old format: just project name string (from current frontend)
                    else {
                        // TEMPORARY: Need to lookup path from archive metadata
                        // For now, we'll need to get it from the backup config
                        $projectName = $project;

                        // Get path from archive backup config
                        if ($archive->backupConfig && !empty($archive->backupConfig['actual_backed_up_items']['compose_projects'])) {
                            foreach ($archive->backupConfig['actual_backed_up_items']['compose_projects'] as $backupProject) {
                                if (is_array($backupProject) && $backupProject['name'] === $projectName && !empty($backupProject['path'])) {
                                    $selectedPaths[] = ltrim($backupProject['path'], '/');
                                    $this->logger->info("DEBUG Added project from backup config: {$projectName} -> {$backupProject['path']}", $server->name);
                                    break;
                                }
                            }
                        }
                    }
                }
            } else {
                $this->logger->info("DEBUG No projects in selected_items", $server->name);
            }

            // Docker configs: specific files from /etc/docker
            // Only add if they were actually backed up (present in backup config)
            if (!empty($operation->selectedItems['configs'])) {
                $backedUpConfigs = $archive->backupConfig['actual_backed_up_items']['configs'] ?? [];
                foreach ($operation->selectedItems['configs'] as $configPath) {
                    $configName = basename($configPath);
                    // Only extract if this config was actually in the backup
                    if (in_array($configName, $backedUpConfigs)) {
                        $selectedPaths[] = ltrim($configPath, '/');
                        $this->logger->info("DEBUG Added config: {$configPath}", $server->name);
                    } else {
                        $this->logger->info("DEBUG Skipping config (not in backup): {$configPath}", $server->name);
                    }
                }
            }

            // Always include container metadata file
            $selectedPaths[] = "tmp/phpborg_docker_containers.json";

            if (!empty($selectedPaths)) {
                // Same as ArchiveRestoreHandler - use escapeshellarg()
                $pathsList = implode(' ', array_map('escapeshellarg', $selectedPaths));
                $cmd .= ' ' . $pathsList;
            }
        }

        $this->logger->info("Executing Borg extract command", $server->name);
        $this->logger->info("Full command: {$cmd}", $server->name);

        // Execute directly like ArchiveRestoreHandler
        $result = $this->sshExecutor->execute($server, $cmd, 7200); // 2 hour timeout

        if ($result['exitCode'] !== 0 && $result['exitCode'] !== 1) {
            throw new RestoreException("Failed to extract from Borg archive: {$result['stderr']}");
        }

        // TODO: Parse progress from stderr and update job progress in real-time

        $this->logger->info("Extraction completed successfully", $server->name);
    }

    /**
     * Adapt paths in docker-compose.yml files for alternative location
     */
    private function adaptComposePaths(object $server, object $operation, int $operationId): void
    {
        if ($operation->composePathAdaptation === 'none') {
            return;
        }

        $this->logger->info("Adapting compose file paths", $server->name);

        // Find all docker-compose.yml files in alternative location
        $result = $this->sshExecutor->execute(
            $server,
            sprintf(
                'find %s -name "docker-compose.yml" -o -name "docker-compose.yaml"',
                escapeshellarg($operation->alternativePath)
            ),
            30
        );

        if ($result['exitCode'] !== 0) {
            $this->logger->warning("Failed to find compose files: {$result['stderr']}", $server->name);
            return;
        }

        $composeFiles = array_filter(explode("\n", trim($result['stdout'])));

        foreach ($composeFiles as $composeFile) {
            $this->adaptComposeFile($server, $composeFile, $operation);
        }
    }

    /**
     * Adapt a single docker-compose.yml file
     */
    private function adaptComposeFile(object $server, string $composeFile, object $operation): void
    {
        // Backup original if requested
        if ($operation->composePathAdaptation === 'generate_new') {
            $this->sshExecutor->execute(
                $server,
                sprintf('cp %s %s.original', escapeshellarg($composeFile), escapeshellarg($composeFile)),
                10
            );
        }

        // Replace volume paths in compose file
        // This is a simplified version - a real implementation would parse YAML properly
        $sedCommand = sprintf(
            "sed -i 's|/var/lib/docker/volumes/|%s/volumes/|g' %s",
            escapeshellarg($operation->alternativePath),
            escapeshellarg($composeFile)
        );

        $result = $this->sshExecutor->execute($server, $sedCommand, 10);

        if ($result['exitCode'] !== 0) {
            $this->logger->warning("Failed to adapt compose file {$composeFile}: {$result['stderr']}", $server->name);
        } else {
            $this->logger->info("Adapted compose file: {$composeFile}", $server->name);
        }
    }

    /**
     * Restart Docker containers
     *
     * @param array<string, mixed> $containers
     */
    private function restartContainers(object $server, array $containers, int $operationId): void
    {
        // Sort by restart_order (reverse order - LIFO)
        usort($containers, fn($a, $b) => ($b['restart_order'] ?? 0) <=> ($a['restart_order'] ?? 0));

        $this->logger->info("Restarting " . count($containers) . " container(s)", $server->name);

        foreach ($containers as $container) {
            $containerName = $container['name'];

            $result = $this->sshExecutor->execute(
                $server,
                sprintf('docker start %s', escapeshellarg($containerName)),
                60
            );

            if ($result['exitCode'] === 0) {
                $this->logger->info("Restarted container: {$containerName}", $server->name);
            } else {
                $this->logger->warning(
                    "Failed to restart container {$containerName}: {$result['stderr']}",
                    $server->name
                );
            }
        }
    }

    /**
     * Run health checks after restore
     */
    private function runHealthChecks(object $server, object $operation, int $operationId): void
    {
        $this->logger->info("Running health checks", $server->name);

        // Check all containers are running
        if (!empty($operation->stoppedContainers)) {
            foreach ($operation->stoppedContainers as $container) {
                $containerName = $container['name'];

                $result = $this->sshExecutor->execute(
                    $server,
                    sprintf('docker inspect -f "{{.State.Running}}" %s', escapeshellarg($containerName)),
                    10
                );

                $isRunning = trim($result['stdout']) === 'true';

                if (!$isRunning) {
                    $this->logger->warning("Container {$containerName} is not running after restore", $server->name);
                } else {
                    $this->logger->info("✓ Container {$containerName} is running", $server->name);
                }
            }
        }
    }

    /**
     * Rollback restore operation
     */
    private function rollback(int $operationId): void
    {
        $operation = $this->restoreOperationRepo->findById($operationId);
        if (!$operation) {
            throw new RestoreException("Restore operation not found: {$operationId}");
        }

        $server = $this->serverRepo->findById($operation->serverId);
        $this->logger->info("Rolling back restore operation", $server->name);

        // Rollback from LVM snapshot (fastest)
        if ($operation->lvmSnapshotCreated && $operation->lvmSnapshotName) {
            $this->rollbackFromLvmSnapshot($server, $operation);
        }
        // Rollback from pre-restore backup
        elseif ($operation->preRestoreBackupCreated && $operation->preRestoreBackupArchive) {
            $this->rollbackFromBackup($server, $operation);
        }
        else {
            throw new RestoreException("No rollback method available for this restore operation");
        }

        $this->restoreOperationRepo->markRolledBack($operationId);
        $this->logger->info("Rollback completed successfully", $server->name);
    }

    /**
     * Rollback using LVM snapshot
     */
    private function rollbackFromLvmSnapshot(object $server, object $operation): void
    {
        $this->logger->info("Rolling back from LVM snapshot: {$operation->lvmSnapshotName}", $server->name);

        $result = $this->sshExecutor->execute(
            $server,
            sprintf('lvconvert --merge /dev/vg_data/%s', escapeshellarg($operation->lvmSnapshotName)),
            120
        );

        if ($result['exitCode'] !== 0) {
            throw new RestoreException("Failed to merge LVM snapshot: {$result['stderr']}");
        }

        $this->logger->info("LVM snapshot merged. Reboot or remount required.", $server->name);
    }

    /**
     * Rollback using pre-restore backup
     */
    private function rollbackFromBackup(object $server, object $operation): void
    {
        $this->logger->info("Rolling back from pre-restore backup: {$operation->preRestoreBackupArchive}", $server->name);

        // This would trigger another restore operation
        // Implementation depends on how pre-restore backup was structured

        throw new RestoreException("Rollback from backup not yet implemented");
    }

    /**
     * Build Borg repository URL for SSH access
     * Same logic as ArchiveRestoreHandler
     */
    private function getBorgRepoUrl(object $repository, object $server): string
    {
        // Determine phpborg server host based on backup type
        if (strtolower($server->backupType) === 'internal') {
            // Use internal IP from settings for internal backups
            $internalIpSetting = $this->settingRepo->findByKey('network.internal_ip');
            $phpborgHost = $internalIpSetting ? $internalIpSetting->value : gethostname();
        } else {
            // Use hostname for external backups
            $phpborgHost = gethostname();
        }

        return sprintf(
            'ssh://phpborg@%s%s',
            $phpborgHost,
            $repository->repoPath
        );
    }
}
