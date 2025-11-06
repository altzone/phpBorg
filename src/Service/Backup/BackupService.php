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
    public function registerDatabaseStrategy(DatabaseBackupInterface $strategy): void
    {
        $this->databaseStrategies[$strategy->getSupportedType()] = $strategy;
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

            // Test reverse SSH connection
            if (!$this->sshExecutor->testReverseConnection($server, $backupServerIp)) {
                throw new BackupException("Reverse SSH connection failed");
            }

            // Prune old archives first
            $this->pruneArchives($repository, $server->name);

            // Prepare backup based on type
            $backupPaths = [];
            $cleanupNeeded = false;
            $dbInfo = null;

            if ($type !== 'backup') {
                // Database backup
                $dbInfo = $this->dbInfoRepo->findByServerAndType($server->id, $type);
                if ($dbInfo === null) {
                    throw new BackupException("Database configuration not found");
                }

                $strategy = $this->databaseStrategies[$type] ?? null;
                if ($strategy === null) {
                    throw new BackupException("No backup strategy registered for type: {$type}");
                }

                $prepareResult = $strategy->prepareBackup($server, $dbInfo);
                $backupPaths = [$prepareResult['path']];
                $cleanupNeeded = $prepareResult['cleanup'];
            } else {
                // Filesystem backup
                $backupPaths = $repository->getBackupPaths();
            }

            // Set repository permissions
            $this->setRepositoryPermissions($server, $repository->repoPath);

            // Create archive name
            $archiveName = $type . '_' . date('Y-m-d_H-i-s');

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
            if ($cleanupNeeded && $dbInfo !== null) {
                $strategy = $this->databaseStrategies[$type];
                $strategy->cleanupBackup($server, $dbInfo);
            }

            if ($result['exitCode'] !== 0) {
                throw new BackupException("Borg backup failed: {$result['stderr']}");
            }

            // Parse backup info
            $archiveInfo = $this->borgExecutor->getArchiveInfo(
                $repository->repoPath . "::{$archiveName}",
                $repository->passphrase
            );

            // Save archive to database
            $this->saveArchive($repository, $archiveInfo);

            // Update repository statistics
            $this->updateRepositoryStats($repository);

            // Update report
            $archiveData = $archiveInfo['archives'] ?? [];
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
        $this->logger->info("Pruning archives (retention: {$repository->retention} days)", $serverName);

        $result = $this->borgExecutor->pruneArchives(
            $repository->repoPath,
            $repository->passphrase,
            $repository->retention
        );

        if ($result['exitCode'] === 0) {
            // Parse output to find deleted archives
            $lines = explode("\n", $result['stderr']);
            foreach ($lines as $line) {
                if (preg_match('/Pruning archive\s+.*\[(.*?)\]/', $line, $matches)) {
                    $archiveId = $matches[1];
                    $this->archiveRepo->deleteByArchiveId($archiveId);
                    $this->logger->info("Removed old archive: {$archiveId}", $serverName);
                }
            }
        }
    }

    /**
     * Build remote backup command
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

        return sprintf(
            "export BORG_PASSPHRASE='%s' && %s create --compression %s --stats --json%s ssh://%s@%s%s::%s %s",
            $repository->passphrase,
            $this->config->borgBinaryPath,
            $repository->compression,
            $excludeArgs,
            $server->host,
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
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($repoPath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $item) {
            @chmod($item->getPathname(), 0700);
            @chgrp($item->getPathname(), $server->host);
            @chown($item->getPathname(), $server->host);
        }
    }

    /**
     * Save archive information to database
     */
    private function saveArchive(BorgRepository $repository, array $archiveInfo): void
    {
        $archiveData = $archiveInfo['archives'] ?? [];
        $stats = $archiveData['stats'] ?? [];

        $this->archiveRepo->create(
            repoId: $repository->repoId,
            name: $archiveData['name'] ?? '',
            archiveId: $archiveData['id'] ?? '',
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
}
