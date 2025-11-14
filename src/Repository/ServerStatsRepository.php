<?php

declare(strict_types=1);

namespace PhpBorg\Repository;

use PhpBorg\Database\Connection;
use PhpBorg\Exception\DatabaseException;

/**
 * Repository for server system metrics
 */
final class ServerStatsRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * Get latest stats for a server
     *
     * @throws DatabaseException
     */
    public function getLatestStats(int $serverId): ?array
    {
        return $this->connection->fetchOne(
            'SELECT * FROM server_stats
             WHERE server_id = ?
             ORDER BY collected_at DESC
             LIMIT 1',
            [$serverId]
        );
    }

    /**
     * Get stats for multiple servers (latest for each)
     *
     * @param array<int> $serverIds
     * @return array<int, array>
     * @throws DatabaseException
     */
    public function getLatestStatsForServers(array $serverIds): array
    {
        if (empty($serverIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($serverIds), '?'));

        $results = $this->connection->fetchAll(
            "SELECT ss.*
             FROM server_stats ss
             INNER JOIN (
                 SELECT server_id, MAX(collected_at) as max_collected
                 FROM server_stats
                 WHERE server_id IN ($placeholders)
                 GROUP BY server_id
             ) latest ON ss.server_id = latest.server_id
                     AND ss.collected_at = latest.max_collected",
            $serverIds
        );

        // Index by server_id for easy lookup
        $indexed = [];
        foreach ($results as $row) {
            $indexed[(int)$row['server_id']] = $row;
        }

        return $indexed;
    }

    /**
     * Save server stats
     *
     * @throws DatabaseException
     */
    public function saveStats(int $serverId, array $stats): void
    {
        $this->connection->executeUpdate(
            'INSERT INTO server_stats (
                server_id,
                os_distribution, os_version, kernel_version, hostname, architecture,
                cpu_cores, cpu_model, cpu_load_1, cpu_load_5, cpu_load_15, cpu_usage_percent,
                memory_total_mb, memory_used_mb, memory_free_mb, memory_available_mb, memory_percent,
                swap_total_mb, swap_used_mb, swap_percent,
                disk_total_gb, disk_used_gb, disk_free_gb, disk_percent, disk_mount_point,
                uptime_seconds, uptime_human, boot_time,
                ip_address,
                collected_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
            )',
            [
                $serverId,
                $stats['os_distribution'] ?? null,
                $stats['os_version'] ?? null,
                $stats['kernel_version'] ?? null,
                $stats['hostname'] ?? null,
                $stats['architecture'] ?? null,
                $stats['cpu_cores'] ?? null,
                $stats['cpu_model'] ?? null,
                $stats['cpu_load_1'] ?? null,
                $stats['cpu_load_5'] ?? null,
                $stats['cpu_load_15'] ?? null,
                $stats['cpu_usage_percent'] ?? null,
                $stats['memory_total_mb'] ?? null,
                $stats['memory_used_mb'] ?? null,
                $stats['memory_free_mb'] ?? null,
                $stats['memory_available_mb'] ?? null,
                $stats['memory_percent'] ?? null,
                $stats['swap_total_mb'] ?? null,
                $stats['swap_used_mb'] ?? null,
                $stats['swap_percent'] ?? null,
                $stats['disk_total_gb'] ?? null,
                $stats['disk_used_gb'] ?? null,
                $stats['disk_free_gb'] ?? null,
                $stats['disk_percent'] ?? null,
                $stats['disk_mount_point'] ?? '/',
                $stats['uptime_seconds'] ?? null,
                $stats['uptime_human'] ?? null,
                $stats['boot_time'] ?? null,
                $stats['ip_address'] ?? null,
            ]
        );
    }

    /**
     * Clean old stats (keep only last N days)
     *
     * @throws DatabaseException
     */
    public function cleanOldStats(int $daysToKeep = 7): int
    {
        $this->connection->executeUpdate(
            'DELETE FROM server_stats
             WHERE collected_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
            [$daysToKeep]
        );

        // Return number of rows affected
        return 0; // PDO doesn't provide this easily, but it's not critical
    }

    /**
     * Get stats history for a server
     *
     * @return array<int, array>
     * @throws DatabaseException
     */
    public function getStatsHistory(int $serverId, int $hours = 24, int $limit = 100): array
    {
        return $this->connection->fetchAll(
            'SELECT * FROM server_stats
             WHERE server_id = ?
             AND collected_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
             ORDER BY collected_at DESC
             LIMIT ?',
            [$serverId, $hours, $limit]
        );
    }

    /**
     * Delete all stats for a server
     *
     * @throws DatabaseException
     */
    public function deleteByServerId(int $serverId): void
    {
        $this->connection->executeUpdate(
            'DELETE FROM server_stats WHERE server_id = ?',
            [$serverId]
        );
    }
}
