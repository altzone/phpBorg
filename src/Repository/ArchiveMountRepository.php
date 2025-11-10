<?php

declare(strict_types=1);

namespace PhpBorg\Repository;

use DateTimeImmutable;
use PhpBorg\Database\Connection;
use PhpBorg\Entity\ArchiveMount;
use PhpBorg\Exception\DatabaseException;

/**
 * Repository for ArchiveMount entities
 */
final class ArchiveMountRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * Find mount by ID
     *
     * @throws DatabaseException
     */
    public function findById(int $id): ?ArchiveMount
    {
        $row = $this->connection->fetchOne(
            'SELECT * FROM archive_mounts WHERE id = ?',
            [$id]
        );

        return $row ? ArchiveMount::fromDatabase($row) : null;
    }

    /**
     * Find mount by archive ID
     *
     * @throws DatabaseException
     */
    public function findByArchiveId(int $archiveId): ?ArchiveMount
    {
        $row = $this->connection->fetchOne(
            'SELECT * FROM archive_mounts WHERE archive_id = ?',
            [$archiveId]
        );

        return $row ? ArchiveMount::fromDatabase($row) : null;
    }

    /**
     * Find mount by mount path
     *
     * @throws DatabaseException
     */
    public function findByMountPath(string $mountPath): ?ArchiveMount
    {
        $row = $this->connection->fetchOne(
            'SELECT * FROM archive_mounts WHERE mount_path = ?',
            [$mountPath]
        );

        return $row ? ArchiveMount::fromDatabase($row) : null;
    }

    /**
     * Find all active mounts
     *
     * @return array<int, ArchiveMount>
     * @throws DatabaseException
     */
    public function findAllActive(): array
    {
        $rows = $this->connection->fetchAll(
            "SELECT * FROM archive_mounts WHERE status = 'mounted' ORDER BY last_access DESC"
        );

        return array_map(fn(array $row) => ArchiveMount::fromDatabase($row), $rows);
    }

    /**
     * Find stale mounts (inactive for more than X minutes)
     *
     * @return array<int, ArchiveMount>
     * @throws DatabaseException
     */
    public function findStale(int $timeoutMinutes = 15): array
    {
        $cutoff = (new DateTimeImmutable())->modify("-{$timeoutMinutes} minutes");

        $rows = $this->connection->fetchAll(
            "SELECT * FROM archive_mounts
             WHERE status = 'mounted'
             AND last_access < ?
             ORDER BY last_access ASC",
            [$cutoff->format('Y-m-d H:i:s')]
        );

        return array_map(fn(array $row) => ArchiveMount::fromDatabase($row), $rows);
    }

    /**
     * Create new mount record
     *
     * @throws DatabaseException
     */
    public function create(
        int $archiveId,
        string $mountPath,
        string $status = 'mounting'
    ): int {
        $now = new DateTimeImmutable();

        $this->connection->executeUpdate(
            'INSERT INTO archive_mounts
             (archive_id, mount_path, status, mounted_at, last_access)
             VALUES (?, ?, ?, ?, ?)',
            [
                $archiveId,
                $mountPath,
                $status,
                $now->format('Y-m-d H:i:s'),
                $now->format('Y-m-d H:i:s')
            ]
        );

        return $this->connection->getLastInsertId();
    }

    /**
     * Update mount status
     *
     * @throws DatabaseException
     */
    public function updateStatus(int $id, string $status, ?string $errorMessage = null): void
    {
        if ($errorMessage !== null) {
            $this->connection->executeUpdate(
                'UPDATE archive_mounts SET status = ?, error_message = ? WHERE id = ?',
                [$status, $errorMessage, $id]
            );
        } else {
            $this->connection->executeUpdate(
                'UPDATE archive_mounts SET status = ? WHERE id = ?',
                [$status, $id]
            );
        }
    }

    /**
     * Update last access time (extends timeout)
     *
     * @throws DatabaseException
     */
    public function updateLastAccess(int $id): void
    {
        $now = new DateTimeImmutable();

        $this->connection->executeUpdate(
            'UPDATE archive_mounts SET last_access = ? WHERE id = ?',
            [$now->format('Y-m-d H:i:s'), $id]
        );
    }

    /**
     * Update last access by archive ID
     *
     * @throws DatabaseException
     */
    public function updateLastAccessByArchiveId(int $archiveId): void
    {
        $now = new DateTimeImmutable();

        $this->connection->executeUpdate(
            'UPDATE archive_mounts SET last_access = ? WHERE archive_id = ?',
            [$now->format('Y-m-d H:i:s'), $archiveId]
        );
    }

    /**
     * Delete mount by ID
     *
     * @throws DatabaseException
     */
    public function deleteById(int $id): void
    {
        $this->connection->executeUpdate(
            'DELETE FROM archive_mounts WHERE id = ?',
            [$id]
        );
    }

    /**
     * Delete mount by archive ID
     *
     * @throws DatabaseException
     */
    public function deleteByArchiveId(int $archiveId): void
    {
        $this->connection->executeUpdate(
            'DELETE FROM archive_mounts WHERE archive_id = ?',
            [$archiveId]
        );
    }

    /**
     * Count active mounts
     *
     * @throws DatabaseException
     */
    public function countActive(): int
    {
        $row = $this->connection->fetchOne(
            "SELECT COUNT(*) as count FROM archive_mounts WHERE status = 'mounted'"
        );

        return (int)($row['count'] ?? 0);
    }
}
