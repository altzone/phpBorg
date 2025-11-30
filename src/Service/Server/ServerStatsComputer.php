<?php

declare(strict_types=1);

namespace PhpBorg\Service\Server;

use PhpBorg\Repository\ArchiveRepository;
use PhpBorg\Repository\ServerRepository;
use PhpBorg\Repository\BackupJobRepository;
use PhpBorg\Repository\BorgRepositoryRepository;
use PhpBorg\Database\Connection;

/**
 * Computes and caches server backup statistics
 *
 * Stats are pre-computed and stored in servers.computed_stats JSON column.
 * They are updated only when relevant events occur:
 * - Backup completed
 * - Archive deleted/pruned
 * - Repository added/deleted
 */
class ServerStatsComputer
{
    private Connection $connection;
    private ArchiveRepository $archiveRepository;
    private ServerRepository $serverRepository;
    private BackupJobRepository $backupJobRepository;
    private BorgRepositoryRepository $repositoryRepository;

    public function __construct(
        Connection $connection,
        ArchiveRepository $archiveRepository,
        ServerRepository $serverRepository,
        BackupJobRepository $backupJobRepository,
        BorgRepositoryRepository $repositoryRepository
    ) {
        $this->connection = $connection;
        $this->archiveRepository = $archiveRepository;
        $this->serverRepository = $serverRepository;
        $this->backupJobRepository = $backupJobRepository;
        $this->repositoryRepository = $repositoryRepository;
    }

    /**
     * Compute and store stats for a specific server
     */
    public function computeForServer(int $serverId): array
    {
        // Get backup counts and storage
        $totalBackups = $this->archiveRepository->countByServerId($serverId);
        $storageStats = $this->archiveRepository->getStorageStatsByServerId($serverId);

        // Get last backup
        $lastBackups = $this->archiveRepository->findRecentByServerId($serverId, 1);
        $lastBackup = !empty($lastBackups) ? $lastBackups[0] : null;

        // Get repository count
        $repositories = $this->repositoryRepository->findByServerId($serverId);
        $repositoryCount = count($repositories);

        // Get success rate (last 30 days)
        $backupStats = $this->backupJobRepository->getStatsByServerId($serverId, 30);
        $successRate = 0;
        if (isset($backupStats['total']) && $backupStats['total'] > 0) {
            $successRate = round(($backupStats['success'] / $backupStats['total']) * 100, 1);
        }

        $stats = [
            'total_backups' => $totalBackups,
            'repository_count' => $repositoryCount,
            'original_size' => $storageStats['total_original_size'],
            'compressed_size' => $storageStats['total_compressed_size'],
            'deduplicated_size' => $storageStats['total_deduplicated_size'],
            'last_backup_at' => $lastBackup?->start?->format('Y-m-d H:i:s'),
            'last_backup_id' => $lastBackup?->id,
            'last_backup_name' => $lastBackup?->name,
            'last_backup_duration' => $lastBackup?->duration,
            'success_rate_30d' => $successRate,
            'computed_at' => date('Y-m-d H:i:s'),
        ];

        // Store in database
        $this->storeStats($serverId, $stats);

        return $stats;
    }

    /**
     * Store computed stats in database
     */
    private function storeStats(int $serverId, array $stats): void
    {
        $this->connection->execute(
            'UPDATE servers SET computed_stats = ?, stats_computed_at = NOW() WHERE id = ?',
            [json_encode($stats), $serverId]
        );
    }

    /**
     * Get cached stats for a server (or compute if not available)
     */
    public function getStats(int $serverId, bool $forceRefresh = false): ?array
    {
        if (!$forceRefresh) {
            $row = $this->connection->fetchOne(
                'SELECT computed_stats FROM servers WHERE id = ?',
                [$serverId]
            );

            if ($row && !empty($row['computed_stats'])) {
                return json_decode($row['computed_stats'], true);
            }
        }

        // Compute if not cached or forced refresh
        return $this->computeForServer($serverId);
    }

    /**
     * Get cached stats for multiple servers
     */
    public function getStatsForServers(array $serverIds): array
    {
        if (empty($serverIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($serverIds), '?'));
        $rows = $this->connection->fetchAll(
            "SELECT id, computed_stats FROM servers WHERE id IN ($placeholders)",
            $serverIds
        );

        $result = [];
        foreach ($rows as $row) {
            if (!empty($row['computed_stats'])) {
                $result[$row['id']] = json_decode($row['computed_stats'], true);
            }
        }

        return $result;
    }

    /**
     * Invalidate stats for a server (will be recomputed on next access)
     */
    public function invalidate(int $serverId): void
    {
        $this->connection->execute(
            'UPDATE servers SET computed_stats = NULL, stats_computed_at = NULL WHERE id = ?',
            [$serverId]
        );
    }

    /**
     * Recompute stats for all servers (batch job)
     */
    public function recomputeAll(): int
    {
        $servers = $this->serverRepository->findAll();
        $count = 0;

        foreach ($servers as $server) {
            $this->computeForServer($server->id);
            $count++;
        }

        return $count;
    }

    /**
     * Update stats incrementally after a new backup
     * More efficient than full recompute
     */
    public function onBackupCreated(int $serverId, array $backupInfo): void
    {
        $currentStats = $this->getStats($serverId);

        if ($currentStats) {
            // Increment counters
            $currentStats['total_backups'] = ($currentStats['total_backups'] ?? 0) + 1;
            $currentStats['original_size'] = ($currentStats['original_size'] ?? 0) + ($backupInfo['original_size'] ?? 0);
            $currentStats['compressed_size'] = ($currentStats['compressed_size'] ?? 0) + ($backupInfo['compressed_size'] ?? 0);
            $currentStats['deduplicated_size'] = ($currentStats['deduplicated_size'] ?? 0) + ($backupInfo['deduplicated_size'] ?? 0);

            // Update last backup info
            $currentStats['last_backup_at'] = $backupInfo['date'] ?? date('Y-m-d H:i:s');
            $currentStats['last_backup_id'] = $backupInfo['id'] ?? null;
            $currentStats['last_backup_name'] = $backupInfo['name'] ?? null;
            $currentStats['last_backup_duration'] = $backupInfo['duration'] ?? null;
            $currentStats['computed_at'] = date('Y-m-d H:i:s');

            $this->storeStats($serverId, $currentStats);
        } else {
            // Full compute if no cached stats
            $this->computeForServer($serverId);
        }
    }

    /**
     * Update stats after backup/archive deletion
     */
    public function onBackupDeleted(int $serverId, array $deletedBackupInfo = []): void
    {
        // For deletion, we do a full recompute to ensure accuracy
        // (could optimize with incremental if needed)
        $this->computeForServer($serverId);
    }
}
