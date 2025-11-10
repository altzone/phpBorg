<?php

declare(strict_types=1);

namespace PhpBorg\Repository;

use PhpBorg\Database\Connection;
use PhpBorg\Entity\BorgRepository;
use PhpBorg\Exception\DatabaseException;

/**
 * Repository for BorgRepository entities
 */
final class BorgRepositoryRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * Get the database connection
     */
    public function getConnection(): Connection
    {
        return $this->connection;
    }

    /**
     * Find repository by ID
     *
     * @throws DatabaseException
     */
    public function findById(int $id): ?BorgRepository
    {
        $row = $this->connection->fetchOne(
            'SELECT *, FROM_BASE64(passphrase) as passphrase FROM repository WHERE id = ?',
            [$id]
        );

        return $row ? BorgRepository::fromDatabase($row) : null;
    }

    /**
     * Find repository by server ID and type
     *
     * @throws DatabaseException
     */
    public function findByServerAndType(int $serverId, string $type): ?BorgRepository
    {
        $row = $this->connection->fetchOne(
            'SELECT *, FROM_BASE64(passphrase) as passphrase FROM repository WHERE server_id = ? AND type = ?',
            [$serverId, $type]
        );

        return $row ? BorgRepository::fromDatabase($row) : null;
    }

    /**
     * Find all repositories for a server
     *
     * @return array<int, BorgRepository>
     * @throws DatabaseException
     */
    public function findByServerId(int $serverId): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT *, FROM_BASE64(passphrase) as passphrase FROM repository WHERE server_id = ? ORDER BY type',
            [$serverId]
        );

        return array_map(fn(array $row) => BorgRepository::fromDatabase($row), $rows);
    }

    /**
     * Find all repositories
     *
     * @return array<int, BorgRepository>
     * @throws DatabaseException
     */
    public function findAll(): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT *, FROM_BASE64(passphrase) as passphrase FROM repository ORDER BY server_id, type'
        );

        return array_map(fn(array $row) => BorgRepository::fromDatabase($row), $rows);
    }

    /**
     * Create new repository
     *
     * @throws DatabaseException
     */
    public function create(
        int $serverId,
        string $repoId,
        string $type,
        int $retention,
        string $encryption,
        string $passphrase,
        string $repoPath,
        string $compression,
        int $rateLimit,
        string $backupPath,
        ?string $exclude = null,
        ?int $keepDaily = null,
        ?int $keepWeekly = null,
        ?int $keepMonthly = null,
        ?int $keepYearly = null
    ): int {
        // Use provided values or defaults
        $keepDaily = $keepDaily ?? $retention;
        $keepWeekly = $keepWeekly ?? 4;
        $keepMonthly = $keepMonthly ?? 6;
        $keepYearly = $keepYearly ?? 0;

        $this->connection->executeUpdate(
            'INSERT INTO repository
             (server_id, repo_id, type, retention, keep_daily, keep_weekly, keep_monthly, keep_yearly,
              encryption, passphrase, repo_path, compression, ratelimit, backup_path, exclude, modified)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, TO_BASE64(?), ?, ?, ?, ?, ?, NOW())',
            [
                $serverId, $repoId, $type, $retention, $keepDaily, $keepWeekly, $keepMonthly, $keepYearly,
                $encryption, $passphrase, $repoPath, $compression, $rateLimit, $backupPath, $exclude
            ]
        );

        return $this->connection->getLastInsertId();
    }

    /**
     * Update repository retention policy
     *
     * @throws DatabaseException
     */
    public function updateRetention(
        int $id,
        int $keepDaily,
        int $keepWeekly,
        int $keepMonthly,
        int $keepYearly
    ): void {
        $this->connection->executeUpdate(
            'UPDATE repository
             SET keep_daily = ?, keep_weekly = ?, keep_monthly = ?, keep_yearly = ?,
                 retention = ?, modified = NOW()
             WHERE id = ?',
            [$keepDaily, $keepWeekly, $keepMonthly, $keepYearly, $keepDaily, $id]
        );
    }

    /**
     * Update repository statistics
     *
     * @throws DatabaseException
     */
    public function updateStatistics(
        string $repoId,
        int $size,
        int $compressedSize,
        int $deduplicatedSize,
        int $totalUniqueChunks,
        int $totalChunks
    ): void {
        $this->connection->executeUpdate(
            'UPDATE repository
             SET size = ?, csize = ?, dsize = ?, ttuchunks = ?, ttchunks = ?, modified = NOW()
             WHERE repo_id = ?',
            [$size, $compressedSize, $deduplicatedSize, $totalUniqueChunks, $totalChunks, $repoId]
        );
    }

    /**
     * Delete repository
     *
     * @throws DatabaseException
     */
    public function delete(int $id): void
    {
        $this->connection->executeUpdate(
            'DELETE FROM repository WHERE id = ?',
            [$id]
        );
    }
}
