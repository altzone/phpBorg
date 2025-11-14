<?php

declare(strict_types=1);

namespace PhpBorg\Repository;

use DateTimeImmutable;
use PhpBorg\Database\Connection;
use PhpBorg\Entity\Archive;
use PhpBorg\Exception\DatabaseException;

/**
 * Repository for Archive entities
 */
final class ArchiveRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * Find archive by ID
     *
     * @throws DatabaseException
     */
    public function findById(int $id): ?Archive
    {
        $row = $this->connection->fetchOne(
            'SELECT * FROM archives WHERE id = ?',
            [$id]
        );

        return $row ? Archive::fromDatabase($row) : null;
    }

    /**
     * Find archive by archive ID (borg archive ID)
     *
     * @throws DatabaseException
     */
    public function findByArchiveId(string $archiveId): ?Archive
    {
        $row = $this->connection->fetchOne(
            'SELECT * FROM archives WHERE archive_id = ?',
            [$archiveId]
        );

        return $row ? Archive::fromDatabase($row) : null;
    }

    /**
     * Find archives by repository ID
     *
     * @return array<int, Archive>
     * @throws DatabaseException
     */
    public function findByRepositoryId(string $repoId): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT * FROM archives WHERE repo_id = ? ORDER BY end DESC',
            [$repoId]
        );

        return array_map(fn(array $row) => Archive::fromDatabase($row), $rows);
    }

    /**
     * Find all archives
     *
     * @return array<int, Archive>
     * @throws DatabaseException
     */
    public function findAll(): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT * FROM archives ORDER BY end DESC'
        );

        return array_map(fn(array $row) => Archive::fromDatabase($row), $rows);
    }

    /**
     * Find all archives with server and repository details
     *
     * @return array<int, array>
     * @throws DatabaseException
     */
    public function findAllWithDetails(): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT a.*, s.name as server_name, r.type as repository_type,
                    m.id as mount_id, m.status as mount_status, m.mount_path
             FROM archives a
             LEFT JOIN servers s ON a.server_id = s.id
             LEFT JOIN repository r ON a.repo_id = r.repo_id
             LEFT JOIN archive_mounts m ON a.id = m.archive_id
             ORDER BY a.end DESC'
        );

        return $rows;
    }

    /**
     * Create new archive
     *
     * @throws DatabaseException
     */
    public function create(
        string $repoId,
        int $serverId,
        string $name,
        string $archiveId,
        float $duration,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        int $compressedSize,
        int $deduplicatedSize,
        int $originalSize,
        int $filesCount
    ): int {
        $this->connection->executeUpdate(
            'INSERT INTO archives
             (repo_id, server_id, nom, archive_id, dur, start, end, csize, dsize, osize, nfiles)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $repoId, $serverId, $name, $archiveId, $duration,
                $start->format('Y-m-d H:i:s'),
                $end->format('Y-m-d H:i:s'),
                $compressedSize, $deduplicatedSize, $originalSize, $filesCount
            ]
        );

        return $this->connection->getLastInsertId();
    }

    /**
     * Delete archive by ID
     *
     * @throws DatabaseException
     */
    public function deleteById(int $id): void
    {
        $this->connection->executeUpdate(
            'DELETE FROM archives WHERE id = ?',
            [$id]
        );
    }

    /**
     * Delete archive by archive ID
     *
     * @throws DatabaseException
     */
    public function deleteByArchiveId(string $archiveId): void
    {
        $this->connection->executeUpdate(
            'DELETE FROM archives WHERE archive_id = ?',
            [$archiveId]
        );
    }

    /**
     * Delete all archives for a repository
     *
     * @throws DatabaseException
     */
    public function deleteByRepositoryId(string $repoId): void
    {
        $this->connection->executeUpdate(
            'DELETE FROM archives WHERE repo_id = ?',
            [$repoId]
        );
    }

    /**
     * Count archives for a repository
     *
     * @throws DatabaseException
     */
    public function countByRepositoryId(string $repoId): int
    {
        $row = $this->connection->fetchOne(
            'SELECT COUNT(*) as count FROM archives WHERE repo_id = ?',
            [$repoId]
        );

        return (int)($row['count'] ?? 0);
    }

    /**
     * Find recent archives by server ID
     *
     * @return array<int, Archive>
     * @throws DatabaseException
     */
    public function findRecentByServerId(int $serverId, int $limit = 10): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT * FROM archives WHERE server_id = ? ORDER BY end DESC LIMIT ?',
            [$serverId, $limit]
        );

        return array_map(fn(array $row) => Archive::fromDatabase($row), $rows);
    }

    /**
     * Count total archives for a server
     *
     * @throws DatabaseException
     */
    public function countByServerId(int $serverId): int
    {
        $row = $this->connection->fetchOne(
            'SELECT COUNT(*) as count FROM archives WHERE server_id = ?',
            [$serverId]
        );

        return (int)($row['count'] ?? 0);
    }

    /**
     * Get storage statistics for a server
     *
     * @return array{total_original_size: int, total_compressed_size: int, total_deduplicated_size: int}
     * @throws DatabaseException
     */
    public function getStorageStatsByServerId(int $serverId): array
    {
        $row = $this->connection->fetchOne(
            'SELECT
                COALESCE(SUM(osize), 0) as total_original_size,
                COALESCE(SUM(csize), 0) as total_compressed_size,
                COALESCE(SUM(dsize), 0) as total_deduplicated_size
             FROM archives
             WHERE server_id = ?',
            [$serverId]
        );

        return [
            'total_original_size' => (int)($row['total_original_size'] ?? 0),
            'total_compressed_size' => (int)($row['total_compressed_size'] ?? 0),
            'total_deduplicated_size' => (int)($row['total_deduplicated_size'] ?? 0),
        ];
    }

    /**
     * Delete all archives for a server
     *
     * @throws DatabaseException
     */
    public function deleteByServerId(int $serverId): void
    {
        $this->connection->execute(
            'DELETE FROM archives WHERE server_id = ?',
            [$serverId]
        );
    }

    /**
     * Get statistics for archives by server ID (count + sizes)
     *
     * @throws DatabaseException
     */
    public function getStatsByServerId(int $serverId): array
    {
        $row = $this->connection->fetchOne(
            'SELECT
                COUNT(*) as count,
                COALESCE(SUM(osize), 0) as total_original_size,
                COALESCE(SUM(csize), 0) as total_compressed_size,
                COALESCE(SUM(dsize), 0) as total_deduplicated_size
             FROM archives
             WHERE server_id = ?',
            [$serverId]
        );

        return [
            'count' => (int)($row['count'] ?? 0),
            'total_original_size' => (int)($row['total_original_size'] ?? 0),
            'total_compressed_size' => (int)($row['total_compressed_size'] ?? 0),
            'total_deduplicated_size' => (int)($row['total_deduplicated_size'] ?? 0),
        ];
    }
}
