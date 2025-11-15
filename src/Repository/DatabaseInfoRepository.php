<?php

declare(strict_types=1);

namespace PhpBorg\Repository;

use PhpBorg\Database\Connection;
use PhpBorg\Entity\DatabaseInfo;
use PhpBorg\Exception\DatabaseException;

/**
 * Repository for DatabaseInfo entities
 */
final class DatabaseInfoRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * Find database info by ID
     *
     * @throws DatabaseException
     */
    public function findById(int $id): ?DatabaseInfo
    {
        $row = $this->connection->fetchOne(
            'SELECT * FROM db_info WHERE id = ?',
            [$id]
        );

        return $row ? DatabaseInfo::fromDatabase($row) : null;
    }

    /**
     * Find database info by server ID and type
     *
     * @throws DatabaseException
     */
    public function findByServerAndType(int $serverId, string $type): ?DatabaseInfo
    {
        $row = $this->connection->fetchOne(
            'SELECT * FROM db_info WHERE server_id = ? AND type = ?',
            [$serverId, $type]
        );

        return $row ? DatabaseInfo::fromDatabase($row) : null;
    }

    /**
     * Find all database configurations for a server
     *
     * @return array<int, DatabaseInfo>
     * @throws DatabaseException
     */
    public function findByServerId(int $serverId): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT * FROM db_info WHERE server_id = ? ORDER BY type',
            [$serverId]
        );

        return array_map(fn(array $row) => DatabaseInfo::fromDatabase($row), $rows);
    }

    /**
     * Create new database configuration
     *
     * @throws DatabaseException
     */
    public function create(
        string $type,
        int $serverId,
        string $dbHost,
        string $dbUser,
        string $dbPassword,
        string $vgName,
        string $lvmPartition,
        string $lvSize,
        string $dataPath,
        string $repoId = ''
    ): int {
        $this->connection->executeUpdate(
            'INSERT INTO db_info
             (type, server_id, repo_id, db_host, db_user, db_pass, vg_name, lvm_part, lvsize, mysql_path, pg_svg_path)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$type, $serverId, $repoId, $dbHost, $dbUser, $dbPassword, $vgName, $lvmPartition, $lvSize, $dataPath, $dataPath]
        );

        return $this->connection->getLastInsertId();
    }

    /**
     * Update repository ID
     *
     * @throws DatabaseException
     */
    public function updateRepositoryId(int $id, string $repoId): void
    {
        $this->connection->executeUpdate(
            'UPDATE db_info SET repo_id = ? WHERE id = ?',
            [$repoId, $id]
        );
    }

    /**
     * Delete database configuration
     *
     * @throws DatabaseException
     */
    public function delete(int $id): void
    {
        $this->connection->executeUpdate(
            'DELETE FROM db_info WHERE id = ?',
            [$id]
        );
    }
}
