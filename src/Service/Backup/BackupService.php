<?php

declare(strict_types=1);

namespace PhpBorg\Service\Backup;

use DateTimeImmutable;
use PhpBorg\Config\Configuration;
use PhpBorg\Entity\BorgRepository;
use PhpBorg\Entity\Server;
use PhpBorg\Exception\BackupException;
use PhpBorg\Logger\LoggerInterface;
use PhpBorg\Repository\ArchiveRepository;
use PhpBorg\Repository\BorgRepositoryRepository;
use PhpBorg\Repository\DatabaseInfoRepository;
use PhpBorg\Repository\ReportRepository;
use PhpBorg\Service\Database\DatabaseBackupInterface;
use PhpBorg\Service\Server\SshExecutor;

/**
 * Main backup orchestration service
 */
final class BackupService
{
    /** @var array<string, DatabaseBackupInterface> */
    private array $databaseStrategies = [];

    public function __construct(
        private readonly Configuration $config,
        private readonly BorgExecutor $borgExecutor,
        private readonly SshExecutor $sshExecutor,
        private readonly BorgRepositoryRepository $repositoryRepo,
        private readonly ArchiveRepository $archiveRepo,
        private readonly DatabaseInfoRepository $dbInfoRepo,
        private readonly ReportRepository $reportRepo,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Register database backup strategy
     */
    public function registerDatabaseStrategy(DatabaseBackupInterface $strategy, ?string $alias = null): void
    {
        $this->databaseStrategies[$strategy->getSupportedType()] = $strategy;

        // Also register under alias if provided
        if ($alias !== null) {
            $this->databaseStrategies[$alias] = $strategy;
        }
    }

    /**
     * Execute backup for a server
     *
     * @throws BackupException
     */
    public function executeBackup(Server $server, string $type = 'backup'): array
    {
        $this->logger->info("Starting {$type} backup", $server->name);

        // Create report
        $reportId = $this->reportRepo->create($server->id, $type);

        try {
            // Get repository configuration
            $repository = $this->repositoryRepo->findByServerAndType($server->id, $type);
            if ($repository === null) {
                throw new BackupException("Repository configuration not found for {$server->name} ({$type})");
            }

            return $this->executeBackupWithRepository($server, $repository, $reportId);

        } catch (BackupException $e) {
            // Log error and update report
            $this->logger->error("Backup failed: {$e->getMessage()}", $server->name);
            $this->reportRepo->updateStatus($reportId, status: 'failed', error: $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];

        } catch (\Exception $e) {
            $error = "Unexpected error during backup: {$e->getMessage()}";
            $this->logger->error($error, $server->name);
            $this->reportRepo->updateStatus($reportId, status: 'failed', error: $error);
            return ['success' => false, 'error' => $error];
        }
    }

    /**
     * Execute backup with a specific repository (used by manual triggers and scheduled jobs with specific repos)
     */
    public function executeBackupWithRepository(Server $server, BorgRepository $repository, ?int $reportId = null): array
    {
        $this->logger->info("Starting backup for repository {$repository->repoId}", $server->name);

        // Create report if not provided
        if ($reportId === null) {
            $reportId = $this->reportRepo->create($server->id, $repository->type ?? 'backup');
        }

        try {
            // Update report with server name
            $this->reportRepo->updateStatus($reportId, currentPosition: $server->host);

            // Check SSH connectivity
            if (!$this->sshExecutor->testConnection($server)) {
                throw new BackupException("SSH connection failed");
            }

            // Determine backup server IP
            $backupServerIp = $server->backupType === 'external'
                ? $this->config->borgServerIpPublic
                : $this->config->borgServerIpPrivate;

            // Test reverse SSH connection (non-blocking - just a warning)
            // Note: With borg serve restriction, we can't test with 'echo' command
            // The real test happens when borg actually connects
            if (!$this->sshExecutor->testReverseConnection($server, $backupServerIp)) {
                $this->logger->warning("Reverse SSH test failed (expected with borg serve restriction)", $server->name);
            }

            // Prune old archives first
            $this->pruneArchives($repository, $server->name);

            // Prepare backup based on type
            $backupPaths = [];
            $cleanupNeeded = false;
            $dbInfo = null;

            // Define database types
            $databaseTypes = ['mysql', 'mariadb', 'postgresql', 'mongodb'];
            
            if (in_array($repository->type, $databaseTypes)) {
                // Database backup
                $dbInfo = $this->dbInfoRepo->findByServerAndType($server->id, $repository->type);
                if ($dbInfo === null) {
                    throw new BackupException("Database configuration not found");
                }

                $strategy = $this->databaseStrategies[$repository->type] ?? null;
                if ($strategy === null) {
                    throw new BackupException("No backup strategy registered for type: {$repository->type}");
                }

                $prepareResult = $strategy->prepareBackup($server, $dbInfo);
                $backupPaths = [$prepareResult['path']];
                $cleanupNeeded = $prepareResult['cleanup'];
            } else {
                // Filesystem backup (files, system, etc.)
                $backupPaths = $repository->getBackupPaths();
            }

            // Set repository permissions
            $this->setRepositoryPermissions($server, $repository->repoPath);

            // Create archive name
            $archiveName = $repository->type . '_' . date('Y-m-d_H-i-s');

            // Execute Borg backup via SSH
            $this->logger->info("Creating Borg archive: {$archiveName}", $server->name);

            $borgCommand = $this->buildRemoteBackupCommand(
                $repository,
                $archiveName,
                $backupPaths,
                $backupServerIp,
                $server
            );

            $result = $this->sshExecutor->execute($server, $borgCommand, 7200);

            // Log borg command output (includes stats and progress info)
            if (!empty($result['stdout'])) {
                foreach (explode("\n", trim($result['stdout'])) as $line) {
                    if (!empty($line)) {
                        $this->logger->info("[BORG] {$line}", $server->name);
                    }
                }
            }
            if (!empty($result['stderr'])) {
                foreach (explode("\n", trim($result['stderr'])) as $line) {
                    if (!empty($line)) {
                        $this->logger->info("[BORG] {$line}", $server->name);
                    }
                }
            }

            // Cleanup database backup if needed
            if ($cleanupNeeded && $dbInfo !== null && in_array($repository->type, $databaseTypes)) {
                $strategy = $this->databaseStrategies[$repository->type];
                $strategy->cleanupBackup($server, $dbInfo);
            }

            if ($result['exitCode'] !== 0) {
                $stderr = $result['stderr'];

                // Detect permission errors and provide helpful message
                if (strpos($stderr, 'Permission denied') !== false ||
                    strpos($stderr, 'LockFailed') !== false ||
                    strpos($stderr, 'lock.exclusive') !== false) {

                    $username = $server->host;
                    $errorMsg = "Borg repository permission error detected!\n\n";
                    $errorMsg .= "The user '{$username}' cannot access the repository at: {$repository->repoPath}\n\n";
                    $errorMsg .= "To fix this issue, run the following commands on the backup server:\n\n";
                    $errorMsg .= "  1. Ensure the user exists:\n";
                    $errorMsg .= "     sudo useradd -d " . dirname($repository->repoPath) . " -m {$username}\n\n";
                    $errorMsg .= "  2. Fix repository ownership:\n";
                    $errorMsg .= "     sudo chown -R {$username}:{$username} {$repository->repoPath}\n\n";
                    $errorMsg .= "  3. Set correct permissions:\n";
                    $errorMsg .= "     sudo chmod -R 700 {$repository->repoPath}\n\n";
                    $errorMsg .= "Original error: " . trim(substr($stderr, 0, 500));

                    throw new BackupException($errorMsg);
                }

                throw new BackupException("Borg backup failed: {$stderr}");
            }

            // Parse backup info
            try {
                $archiveInfo = $this->borgExecutor->getArchiveInfo(
                    $repository->repoPath . "::{$archiveName}",
                    $repository->passphrase
                );

                // Save archive to database
                $this->saveArchive($repository, $archiveInfo);
            } catch (\Exception $e) {
                $this->logger->error(
                    "Failed to save archive to database: {$e->getMessage()}. Backup exists in Borg but not in database!",
                    $server->name
                );
                // Don't throw - backup succeeded in Borg, just database recording failed
                // The sync function can recover this later
            }

            // Update repository statistics
            $this->updateRepositoryStats($repository);

            // Update report
            // Extract archive data (handle both singular and plural formats)
            if (isset($archiveInfo['archive'])) {
                $archiveData = $archiveInfo['archive'];
            } elseif (isset($archiveInfo['archives'][0])) {
                $archiveData = $archiveInfo['archives'][0];
            } else {
                $archiveData = [];
            }
            $stats = $archiveData['stats'] ?? [];

            $this->reportRepo->updateStatus(
                $reportId,
                originalSize: (int)($stats['original_size'] ?? 0),
                compressedSize: (int)($stats['compressed_size'] ?? 0),
                deduplicatedSize: (int)($stats['deduplicated_size'] ?? 0),
                duration: (float)($archiveData['duration'] ?? 0),
                archiveCount: 1,
                filesCount: (int)($stats['nfiles'] ?? 0)
            );

            $this->reportRepo->complete($reportId, false);

            $this->logger->info("Backup completed successfully", $server->name);

            return [
                'success' => true,
                'reportId' => $reportId,
                'archiveName' => $archiveName,
            ];
        } catch (BackupException $e) {
            $this->logger->error("Backup failed: {$e->getMessage()}", $server->name);
            $this->reportRepo->complete($reportId, true, $e->getMessage());

            return [
                'success' => false,
                'reportId' => $reportId,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Prune old archives according to retention policy
     */
    public function pruneArchives(BorgRepository $repository, string $serverName): void
    {
        $this->logger->info(
            "Pruning archives (keep: {$repository->keepDaily}d, {$repository->keepWeekly}w, " .
            "{$repository->keepMonthly}m, {$repository->keepYearly}y)",
            $serverName
        );

        $result = $this->borgExecutor->pruneArchives(
            $repository->repoPath,
            $repository->passphrase,
            $repository->keepDaily,
            $repository->keepWeekly,
            $repository->keepMonthly,
            $repository->keepYearly
        );

        if ($result['exitCode'] === 0) {
            // Parse output to find deleted archives
            $lines = explode("\n", $result['stderr']);
            $deletedCount = 0;
            foreach ($lines as $line) {
                if (preg_match('/Pruning archive\s+.*\[(.*?)\]/', $line, $matches)) {
                    $archiveId = $matches[1];
                    $this->archiveRepo->deleteByArchiveId($archiveId);
                    $this->logger->info("Removed old archive: {$archiveId}", $serverName);
                    $deletedCount++;
                }
            }

            if ($deletedCount > 0) {
                $this->logger->info("Pruned {$deletedCount} archives", $serverName);
            } else {
                $this->logger->info("No archives to prune - all within retention policy", $serverName);
            }
        } else {
            $this->logger->error("Prune failed: {$result['stderr']}", $serverName);
        }
    }

    /**
     * Build remote backup command
     * Uses secure borg serve architecture with SSH key authentication
     */
    private function buildRemoteBackupCommand(
        BorgRepository $repository,
        string $archiveName,
        array $paths,
        string $backupServerIp,
        Server $server
    ): string {
        $excludeArgs = '';
        foreach ($repository->getExclusionPatterns() as $pattern) {
            $excludeArgs .= " --exclude " . escapeshellarg($pattern);
        }

        $pathsString = implode(' ', array_map('escapeshellarg', $paths));

        // Properly escape passphrase for shell command
        $escapedPassphrase = escapeshellarg($repository->passphrase);

        // Path to SSH private key on remote server (deployed during setup)
        $sshKeyPath = '/root/.ssh/phpborg_backup';

        // Backup server user (from server config, defaults to 'phpborg')
        $backupServerUser = 'phpborg';

        // Build borg create command with secure SSH
        // BORG_RSH specifies SSH options including the identity file
        return sprintf(
            "export BORG_PASSPHRASE=%s && export BORG_RSH='ssh -i %s -o StrictHostKeyChecking=accept-new' && %s create --compression %s --stats --json%s ssh://%s@%s%s::%s %s",
            $escapedPassphrase,
            escapeshellarg($sshKeyPath),
            $this->config->borgBinaryPath,
            $repository->compression,
            $excludeArgs,
            $backupServerUser,
            $backupServerIp,
            $repository->repoPath,
            $archiveName,
            $pathsString
        );
    }

    /**
     * Set repository file permissions
     */
    private function setRepositoryPermissions(Server $server, string $repoPath): void
    {
        if (!is_dir($repoPath)) {
            $this->logger->warning("Repository path does not exist: {$repoPath}", $server->name);
            return;
        }

        // Modern architecture: repository owned by phpborg user with proper SSH key auth
        $phpborgUser = 'phpborg';
        
        // Ensure repository is accessible by phpborg user
        if (posix_geteuid() === 0) {
            // If running as root, set proper ownership
            $this->logger->info("Setting repository ownership to {$phpborgUser}", $server->name);
            if (!chown($repoPath, $phpborgUser) || !chgrp($repoPath, $phpborgUser)) {
                $this->logger->warning("Failed to set repository ownership", $server->name);
            }
        } else {
            // If not running as root, just ensure basic permissions
            $this->logger->debug("Ensuring basic repository permissions (non-root execution)", $server->name);
        }
        
        // Set basic directory permissions (owner read/write/execute)
        try {
            if (!chmod($repoPath, 0700)) {
                $this->logger->warning("Failed to set repository permissions", $server->name);
            } else {
                $this->logger->debug("Repository permissions set successfully", $server->name);
            }
        } catch (\Exception $e) {
            $this->logger->warning("Error setting permissions: {$e->getMessage()}", $server->name);
        }
    }

    /**
     * Save archive information to database
     */
    private function saveArchive(BorgRepository $repository, array $archiveInfo): void
    {
        // Borg can return archive info in two formats:
        // 1. After backup: {archive: {...}} - singular
        // 2. From borg info: {archives: [{...}]} - plural array
        if (isset($archiveInfo['archive'])) {
            $archiveData = $archiveInfo['archive'];
        } elseif (isset($archiveInfo['archives']) && is_array($archiveInfo['archives']) && count($archiveInfo['archives']) > 0) {
            $archiveData = $archiveInfo['archives'][0];
        } else {
            throw new BackupException(
                "Invalid archive info structure. Borg response: " . json_encode($archiveInfo, JSON_PRETTY_PRINT)
            );
        }

        $stats = $archiveData['stats'] ?? [];

        // Validate that we have the required fields
        $archiveId = $archiveData['id'] ?? '';
        $archiveName = $archiveData['name'] ?? '';

        if (empty($archiveId)) {
            throw new BackupException(
                "Archive ID is empty. Borg response: " . json_encode($archiveInfo, JSON_PRETTY_PRINT)
            );
        }

        if (empty($archiveName)) {
            throw new BackupException(
                "Archive name is empty. Borg response: " . json_encode($archiveInfo, JSON_PRETTY_PRINT)
            );
        }

        // Check if archive already exists (avoid duplicate key error)
        $existingArchive = $this->findArchiveByArchiveId($archiveId);
        if ($existingArchive !== null) {
            $this->logger->warning(
                "Archive {$archiveId} already exists in database, skipping insert",
                'BACKUP'
            );
            return;
        }

        $this->archiveRepo->create(
            repoId: $repository->repoId,
            serverId: $repository->serverId,
            name: $archiveName,
            archiveId: $archiveId,
            duration: (float)($archiveData['duration'] ?? 0),
            start: new DateTimeImmutable($archiveData['start'] ?? 'now'),
            end: new DateTimeImmutable($archiveData['end'] ?? 'now'),
            compressedSize: (int)($stats['compressed_size'] ?? 0),
            deduplicatedSize: (int)($stats['deduplicated_size'] ?? 0),
            originalSize: (int)($stats['original_size'] ?? 0),
            filesCount: (int)($stats['nfiles'] ?? 0)
        );
    }

    /**
     * Find archive by archive ID
     */
    private function findArchiveByArchiveId(string $archiveId): ?int
    {
        try {
            $result = $this->archiveRepo->findByArchiveId($archiveId);
            return $result ? $result->id : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Update repository statistics
     */
    private function updateRepositoryStats(BorgRepository $repository): void
    {
        $info = $this->borgExecutor->getRepositoryInfo($repository->repoPath, $repository->passphrase);

        $cacheStats = $info['cache']['stats'] ?? [];

        $this->repositoryRepo->updateStatistics(
            repoId: $repository->repoId,
            size: (int)($cacheStats['total_size'] ?? 0),
            compressedSize: (int)($cacheStats['total_csize'] ?? 0),
            deduplicatedSize: (int)($cacheStats['unique_csize'] ?? 0),
            totalUniqueChunks: (int)($cacheStats['total_unique_chunks'] ?? 0),
            totalChunks: (int)($cacheStats['total_chunks'] ?? 0)
        );
    }

    /**
     * Synchronize archives from Borg repository to database
     * This recovers archives that exist in Borg but are missing from the database
     *
     * @return array{synced: int, errors: int, details: array}
     */
    public function syncArchivesFromBorg(?int $serverId = null, ?string $type = null): array
    {
        $this->logger->info("Starting archive synchronization from Borg repositories", 'SYNC');

        $synced = 0;
        $errors = 0;
        $details = [];

        // Get all repositories to sync
        $repositories = [];
        if ($serverId !== null && $type !== null) {
            $repo = $this->repositoryRepo->findByServerAndType($serverId, $type);
            if ($repo) {
                $repositories[] = $repo;
            }
        } elseif ($serverId !== null) {
            $repositories = $this->repositoryRepo->findByServerId($serverId);
        } else {
            $repositories = $this->repositoryRepo->findAll();
        }

        foreach ($repositories as $repository) {
            $this->logger->info("Syncing repository: {$repository->repoId}", 'SYNC');

            try {
                // Get all archives from Borg using borg list --json
                $borgArchives = $this->borgExecutor->listArchives($repository->repoPath, $repository->passphrase);

                $this->logger->info(
                    "Found " . count($borgArchives) . " archives in Borg repository {$repository->repoId}",
                    'SYNC'
                );

                // Get existing archives from database
                $dbArchives = $this->archiveRepo->findByRepositoryId($repository->repoId);
                $dbArchiveIds = array_map(fn($archive) => $archive->archiveId, $dbArchives);

                // Import missing archives
                foreach ($borgArchives as $borgArchive) {
                    $archiveId = $borgArchive['id'] ?? '';
                    $archiveName = $borgArchive['name'] ?? '';

                    if (empty($archiveId)) {
                        $this->logger->warning("Skipping archive with empty ID in repository {$repository->repoId}", 'SYNC');
                        $errors++;
                        continue;
                    }

                    // Check if archive already exists in database
                    if (in_array($archiveId, $dbArchiveIds)) {
                        $this->logger->debug("Archive {$archiveName} already exists in database", 'SYNC');
                        continue;
                    }

                    // Archive is missing from database - fetch detailed info and import
                    $this->logger->info("Importing missing archive: {$archiveName}", 'SYNC');

                    try {
                        $archiveInfo = $this->borgExecutor->getArchiveInfo(
                            $repository->repoPath . "::{$archiveName}",
                            $repository->passphrase
                        );

                        $this->saveArchive($repository, $archiveInfo);
                        $synced++;

                        $details[] = [
                            'repository' => $repository->repoId,
                            'archive' => $archiveName,
                            'action' => 'imported',
                        ];
                    } catch (\Exception $e) {
                        $this->logger->error(
                            "Failed to import archive {$archiveName}: {$e->getMessage()}",
                            'SYNC'
                        );
                        $errors++;

                        $details[] = [
                            'repository' => $repository->repoId,
                            'archive' => $archiveName,
                            'action' => 'error',
                            'error' => $e->getMessage(),
                        ];
                    }
                }

                // Update repository statistics
                $this->updateRepositoryStats($repository);

            } catch (\Exception $e) {
                $this->logger->error(
                    "Failed to sync repository {$repository->repoId}: {$e->getMessage()}",
                    'SYNC'
                );
                $errors++;

                $details[] = [
                    'repository' => $repository->repoId,
                    'action' => 'repository_error',
                    'error' => $e->getMessage(),
                ];
            }
        }

        $this->logger->info("Sync completed: {$synced} imported, {$errors} errors", 'SYNC');

        return [
            'synced' => $synced,
            'errors' => $errors,
            'details' => $details,
        ];
    }
}
