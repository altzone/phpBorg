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
}
