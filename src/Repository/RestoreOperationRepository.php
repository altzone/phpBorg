<?php

declare(strict_types=1);

namespace PhpBorg\Repository;

use PhpBorg\Database\Connection;
use PhpBorg\Entity\RestoreOperation;
use PhpBorg\Exception\DatabaseException;
use DateTimeImmutable;

/**
 * Repository for RestoreOperation entities
 */
final class RestoreOperationRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * Find restore operation by ID
     *
     * @throws DatabaseException
     */
    public function findById(int $id): ?RestoreOperation
    {
        $row = $this->connection->fetchOne(
            'SELECT * FROM restore_operations WHERE id = ?',
            [$id]
        );

        return $row ? RestoreOperation::fromDatabase($row) : null;
    }

    /**
     * Find all restore operations
     *
     * @return array<int, RestoreOperation>
     * @throws DatabaseException
     */
    public function findAll(): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT * FROM restore_operations ORDER BY created_at DESC'
        );

        return array_map(fn(array $row) => RestoreOperation::fromDatabase($row), $rows);
    }

    /**
     * Find restore operations by server
     *
     * @return array<int, RestoreOperation>
     * @throws DatabaseException
     */
    public function findByServerId(int $serverId): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT * FROM restore_operations WHERE server_id = ? ORDER BY created_at DESC',
            [$serverId]
        );

        return array_map(fn(array $row) => RestoreOperation::fromDatabase($row), $rows);
    }

    /**
     * Find restore operations by archive
     *
     * @return array<int, RestoreOperation>
     * @throws DatabaseException
     */
    public function findByArchiveId(int $archiveId): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT * FROM restore_operations WHERE archive_id = ? ORDER BY created_at DESC',
            [$archiveId]
        );

        return array_map(fn(array $row) => RestoreOperation::fromDatabase($row), $rows);
    }

    /**
     * Find restore operations that can still be rolled back
     *
     * @return array<int, RestoreOperation>
     * @throws DatabaseException
     */
    public function findRollbackable(): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT * FROM restore_operations
             WHERE can_rollback_until IS NOT NULL
             AND can_rollback_until > NOW()
             AND status = "completed"
             ORDER BY can_rollback_until ASC'
        );

        return array_map(fn(array $row) => RestoreOperation::fromDatabase($row), $rows);
    }

    /**
     * Find restore operations by source type
     *
     * @return array<int, RestoreOperation>
     * @throws DatabaseException
     */
    public function findBySourceType(string $sourceType): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT * FROM restore_operations WHERE source_type = ? ORDER BY created_at DESC',
            [$sourceType]
        );

        return array_map(fn(array $row) => RestoreOperation::fromDatabase($row), $rows);
    }

    /**
     * Create a new restore operation
     *
     * @param array<string, mixed> $data
     * @throws DatabaseException
     */
    public function create(array $data): int
    {
        $sql = 'INSERT INTO restore_operations (
            archive_id, server_id, user_id, source_type,
            mode, restore_type, destination, alternative_path,
            compose_path_adaptation, selected_items,
            lvm_snapshot_created, lvm_snapshot_name,
            pre_restore_backup_created, pre_restore_backup_archive,
            auto_restart, stopped_containers,
            status, generated_script,
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())';

        $params = [
            $data['archive_id'],
            $data['server_id'],
            $data['user_id'],
            $data['source_type'],
            $data['mode'] ?? 'express',
            $data['restore_type'],
            $data['destination'],
            $data['alternative_path'] ?? null,
            $data['compose_path_adaptation'] ?? null,
            isset($data['selected_items']) ? json_encode($data['selected_items']) : null,
            (int)($data['lvm_snapshot_created'] ?? 0),
            $data['lvm_snapshot_name'] ?? null,
            (int)($data['pre_restore_backup_created'] ?? 0),
            $data['pre_restore_backup_archive'] ?? null,
            (int)($data['auto_restart'] ?? 1),
            isset($data['stopped_containers']) ? json_encode($data['stopped_containers']) : null,
            $data['status'] ?? 'pending',
            $data['generated_script'] ?? null,
        ];

        $this->connection->execute($sql, $params);
        return $this->connection->getLastInsertId();
    }

    /**
     * Update restore operation status
     *
     * @throws DatabaseException
     */
    public function updateStatus(int $id, string $status, ?string $errorMessage = null): void
    {
        $sql = 'UPDATE restore_operations
                SET status = ?,
                    error_message = ?,
                    updated_at = NOW()';

        $params = [$status, $errorMessage];

        // Set started_at on first run
        if ($status === 'running') {
            $sql .= ', started_at = COALESCE(started_at, NOW())';
        }

        // Set completed_at when done
        if (in_array($status, ['completed', 'failed', 'rolled_back'])) {
            $sql .= ', completed_at = NOW()';
        }

        $sql .= ' WHERE id = ?';
        $params[] = $id;

        $this->connection->execute($sql, $params);
    }

    /**
     * Update restore progress
     *
     * @param array<string, mixed> $itemsRestored
     * @throws DatabaseException
     */
    public function updateProgress(int $id, array $itemsRestored, int $bytesRestored): void
    {
        $this->connection->execute(
            'UPDATE restore_operations
             SET items_restored = ?,
                 bytes_restored = ?,
                 updated_at = NOW()
             WHERE id = ?',
            [json_encode($itemsRestored), $bytesRestored, $id]
        );
    }

    /**
     * Mark LVM snapshot as created
     *
     * @throws DatabaseException
     */
    public function markLvmSnapshotCreated(int $id, string $snapshotName): void
    {
        $this->connection->execute(
            'UPDATE restore_operations
             SET lvm_snapshot_created = TRUE,
                 lvm_snapshot_name = ?,
                 updated_at = NOW()
             WHERE id = ?',
            [$snapshotName, $id]
        );
    }

    /**
     * Mark pre-restore backup as created
     *
     * @throws DatabaseException
     */
    public function markPreRestoreBackupCreated(int $id, string $archiveName): void
    {
        $this->connection->execute(
            'UPDATE restore_operations
             SET pre_restore_backup_created = TRUE,
                 pre_restore_backup_archive = ?,
                 updated_at = NOW()
             WHERE id = ?',
            [$archiveName, $id]
        );
    }

    /**
     * Set rollback window (8 hours from now)
     *
     * @throws DatabaseException
     */
    public function setRollbackWindow(int $id, int $hours = 8): void
    {
        $until = (new DateTimeImmutable())->modify("+{$hours} hours");

        $this->connection->execute(
            'UPDATE restore_operations
             SET can_rollback_until = ?,
                 updated_at = NOW()
             WHERE id = ?',
            [$until->format('Y-m-d H:i:s'), $id]
        );
    }

    /**
     * Mark operation as rolled back
     *
     * @throws DatabaseException
     */
    public function markRolledBack(int $id): void
    {
        $this->connection->execute(
            'UPDATE restore_operations
             SET status = "rolled_back",
                 rolled_back_at = NOW(),
                 updated_at = NOW()
             WHERE id = ?',
            [$id]
        );
    }

    /**
     * Update stopped containers list
     *
     * @param array<string, mixed> $containers
     * @throws DatabaseException
     */
    public function updateStoppedContainers(int $id, array $containers): void
    {
        $this->connection->execute(
            'UPDATE restore_operations
             SET stopped_containers = ?,
                 updated_at = NOW()
             WHERE id = ?',
            [json_encode($containers), $id]
        );
    }

    /**
     * Cleanup expired rollback capabilities (called by cron)
     *
     * @throws DatabaseException
     */
    public function cleanupExpiredRollbacks(): int
    {
        $this->connection->execute(
            'UPDATE restore_operations
             SET can_rollback_until = NULL
             WHERE can_rollback_until < NOW()
             AND can_rollback_until IS NOT NULL'
        );

        return $this->connection->affectedRows();
    }
}
