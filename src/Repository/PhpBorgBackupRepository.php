<?php

declare(strict_types=1);

namespace PhpBorg\Repository;

use DateTimeImmutable;
use PhpBorg\Database\Connection;
use PhpBorg\Entity\PhpBorgBackup;
use PhpBorg\Exception\DatabaseException;

/**
 * Repository for PhpBorgBackup entities
 */
final class PhpBorgBackupRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * Find backup by ID
     *
     * @throws DatabaseException
     */
    public function findById(int $id): ?PhpBorgBackup
    {
        $row = $this->connection->fetchOne(
            'SELECT * FROM phpborg_backups WHERE id = ?',
            [$id]
        );

        return $row ? PhpBorgBackup::fromDatabase($row) : null;
    }

    /**
     * Find backup by filename
     *
     * @throws DatabaseException
     */
    public function findByFilename(string $filename): ?PhpBorgBackup
    {
        $row = $this->connection->fetchOne(
            'SELECT * FROM phpborg_backups WHERE filename = ?',
            [$filename]
        );

        return $row ? PhpBorgBackup::fromDatabase($row) : null;
    }

    /**
     * Find backup by SHA256 hash
     *
     * @throws DatabaseException
     */
    public function findByHash(string $hash): ?PhpBorgBackup
    {
        $row = $this->connection->fetchOne(
            'SELECT * FROM phpborg_backups WHERE hash_sha256 = ?',
            [$hash]
        );

        return $row ? PhpBorgBackup::fromDatabase($row) : null;
    }

    /**
     * Find all backups
     *
     * @return array<int, PhpBorgBackup>
     * @throws DatabaseException
     */
    public function findAll(): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT * FROM phpborg_backups ORDER BY created_at DESC'
        );

        return array_map(fn(array $row) => PhpBorgBackup::fromDatabase($row), $rows);
    }

    /**
     * Find backups by type
     *
     * @param string $type One of: manual, pre_update, scheduled
     * @return array<int, PhpBorgBackup>
     * @throws DatabaseException
     */
    public function findByType(string $type): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT * FROM phpborg_backups WHERE backup_type = ? ORDER BY created_at DESC',
            [$type]
        );

        return array_map(fn(array $row) => PhpBorgBackup::fromDatabase($row), $rows);
    }

    /**
     * Find latest pre-update backup
     *
     * @throws DatabaseException
     */
    public function findLatestPreUpdate(): ?PhpBorgBackup
    {
        $row = $this->connection->fetchOne(
            "SELECT * FROM phpborg_backups WHERE backup_type = 'pre_update' ORDER BY created_at DESC LIMIT 1"
        );

        return $row ? PhpBorgBackup::fromDatabase($row) : null;
    }

    /**
     * Count backups
     *
     * @throws DatabaseException
     */
    public function count(): int
    {
        $result = $this->connection->fetchOne('SELECT COUNT(*) as count FROM phpborg_backups');
        return (int)($result['count'] ?? 0);
    }

    /**
     * Count backups by type
     *
     * @throws DatabaseException
     */
    public function countByType(string $type): int
    {
        $result = $this->connection->fetchOne(
            'SELECT COUNT(*) as count FROM phpborg_backups WHERE backup_type = ?',
            [$type]
        );
        return (int)($result['count'] ?? 0);
    }

    /**
     * Create a new backup record
     *
     * @throws DatabaseException
     */
    public function create(
        string $filename,
        string $filepath,
        int $sizeBytes,
        bool $encrypted,
        string $hashSha256,
        string $phpborgVersion,
        string $phpVersion,
        ?string $mysqlVersion,
        ?string $nodeVersion,
        ?string $borgVersion,
        ?int $createdBy,
        string $backupType,
        ?string $notes = null
    ): int {
        $this->connection->execute(
            'INSERT INTO phpborg_backups (
                filename, filepath, size_bytes, encrypted, hash_sha256,
                phpborg_version, php_version, mysql_version, node_version, borg_version,
                created_at, created_by, backup_type, notes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?)',
            [
                $filename,
                $filepath,
                $sizeBytes,
                $encrypted ? 1 : 0,
                $hashSha256,
                $phpborgVersion,
                $phpVersion,
                $mysqlVersion,
                $nodeVersion,
                $borgVersion,
                $createdBy,
                $backupType,
                $notes,
            ]
        );

        return $this->connection->lastInsertId();
    }

    /**
     * Update backup notes
     *
     * @throws DatabaseException
     */
    public function updateNotes(int $id, string $notes): void
    {
        $this->connection->execute(
            'UPDATE phpborg_backups SET notes = ? WHERE id = ?',
            [$notes, $id]
        );
    }

    /**
     * Delete backup record
     *
     * @throws DatabaseException
     */
    public function delete(int $id): void
    {
        $this->connection->execute(
            'DELETE FROM phpborg_backups WHERE id = ?',
            [$id]
        );
    }

    /**
     * Find oldest backups to cleanup (keep only N most recent)
     *
     * @param int $keepCount Number of backups to keep
     * @return array<int, PhpBorgBackup>
     * @throws DatabaseException
     */
    public function findOldestForCleanup(int $keepCount): array
    {
        // Keep the N most recent backups, return the rest
        $rows = $this->connection->fetchAll(
            'SELECT * FROM phpborg_backups
             ORDER BY created_at DESC
             LIMIT 999999 OFFSET ?',
            [$keepCount]
        );

        return array_map(fn(array $row) => PhpBorgBackup::fromDatabase($row), $rows);
    }

    /**
     * Get total size of all backups in bytes
     *
     * @throws DatabaseException
     */
    public function getTotalSize(): int
    {
        $result = $this->connection->fetchOne(
            'SELECT SUM(size_bytes) as total FROM phpborg_backups'
        );
        return (int)($result['total'] ?? 0);
    }

    /**
     * Get statistics about backups
     *
     * @return array{total: int, manual: int, pre_update: int, scheduled: int, encrypted: int, total_size: int}
     * @throws DatabaseException
     */
    public function getStats(): array
    {
        $result = $this->connection->fetchOne(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN backup_type = 'manual' THEN 1 ELSE 0 END) as manual,
                SUM(CASE WHEN backup_type = 'pre_update' THEN 1 ELSE 0 END) as pre_update,
                SUM(CASE WHEN backup_type = 'scheduled' THEN 1 ELSE 0 END) as scheduled,
                SUM(CASE WHEN encrypted = 1 THEN 1 ELSE 0 END) as encrypted,
                SUM(size_bytes) as total_size
             FROM phpborg_backups"
        );

        return [
            'total' => (int)($result['total'] ?? 0),
            'manual' => (int)($result['manual'] ?? 0),
            'pre_update' => (int)($result['pre_update'] ?? 0),
            'scheduled' => (int)($result['scheduled'] ?? 0),
            'encrypted' => (int)($result['encrypted'] ?? 0),
            'total_size' => (int)($result['total_size'] ?? 0),
        ];
    }
}
