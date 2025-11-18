<?php

declare(strict_types=1);

namespace PhpBorg\Entity;

use DateTimeImmutable;

/**
 * Restore operation entity (Docker, MySQL, PostgreSQL, MongoDB, Filesystem, System)
 */
final readonly class RestoreOperation
{
    public function __construct(
        public int $id,
        public int $archiveId,
        public int $serverId,
        public int $userId,

        // Source type (what we're restoring)
        public string $sourceType, // docker, mysql, postgresql, mongodb, filesystem, system

        // Configuration
        public string $mode, // express, pro_safe
        public string $restoreType, // full, volumes_only, compose_only, database_only, custom, files_only
        public string $destination, // in_place, alternative
        public ?string $alternativePath,

        // Options
        public ?string $composePathAdaptation, // none, auto_modify, generate_new
        public ?array $selectedItems, // {volumes: [...], projects: [...], configs: [...]}

        // Protections
        public bool $lvmSnapshotCreated,
        public ?string $lvmSnapshotName,
        public bool $preRestoreBackupCreated,
        public ?string $preRestoreBackupArchive,
        public bool $autoRestart,

        // Containers
        public ?array $stoppedContainers, // [{name, id, restart_order}]

        // Execution
        public string $status, // pending, running, completed, failed, rolled_back
        public ?DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $completedAt,
        public ?string $errorMessage,

        // Script
        public ?string $generatedScript,
        public bool $scriptExecuted,

        // Rollback capability (8 hours)
        public ?DateTimeImmutable $canRollbackUntil,
        public ?DateTimeImmutable $rolledBackAt,

        // Tracking
        public ?array $itemsRestored, // Progress tracking
        public ?int $bytesRestored,

        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $updatedAt = null,
    ) {
    }

    /**
     * Create RestoreOperation from database row
     *
     * @param array<string, mixed> $row
     */
    public static function fromDatabase(array $row): self
    {
        return new self(
            id: (int)$row['id'],
            archiveId: (int)$row['archive_id'],
            serverId: (int)$row['server_id'],
            userId: (int)$row['user_id'],

            sourceType: (string)$row['source_type'],

            mode: (string)$row['mode'],
            restoreType: (string)$row['restore_type'],
            destination: (string)$row['destination'],
            alternativePath: $row['alternative_path'] ?? null,

            composePathAdaptation: $row['compose_path_adaptation'] ?? null,
            selectedItems: isset($row['selected_items']) && $row['selected_items'] !== null
                ? json_decode($row['selected_items'], true)
                : null,

            lvmSnapshotCreated: (bool)($row['lvm_snapshot_created'] ?? false),
            lvmSnapshotName: $row['lvm_snapshot_name'] ?? null,
            preRestoreBackupCreated: (bool)($row['pre_restore_backup_created'] ?? false),
            preRestoreBackupArchive: $row['pre_restore_backup_archive'] ?? null,
            autoRestart: (bool)($row['auto_restart'] ?? true),

            stoppedContainers: isset($row['stopped_containers']) && $row['stopped_containers'] !== null
                ? json_decode($row['stopped_containers'], true)
                : null,

            status: (string)$row['status'],
            startedAt: isset($row['started_at']) && $row['started_at'] !== null
                ? new DateTimeImmutable($row['started_at'])
                : null,
            completedAt: isset($row['completed_at']) && $row['completed_at'] !== null
                ? new DateTimeImmutable($row['completed_at'])
                : null,
            errorMessage: $row['error_message'] ?? null,

            generatedScript: $row['generated_script'] ?? null,
            scriptExecuted: (bool)($row['script_executed'] ?? false),

            canRollbackUntil: isset($row['can_rollback_until']) && $row['can_rollback_until'] !== null
                ? new DateTimeImmutable($row['can_rollback_until'])
                : null,
            rolledBackAt: isset($row['rolled_back_at']) && $row['rolled_back_at'] !== null
                ? new DateTimeImmutable($row['rolled_back_at'])
                : null,

            itemsRestored: isset($row['items_restored']) && $row['items_restored'] !== null
                ? json_decode($row['items_restored'], true)
                : null,
            bytesRestored: isset($row['bytes_restored']) && $row['bytes_restored'] !== null
                ? (int)$row['bytes_restored']
                : null,

            createdAt: new DateTimeImmutable($row['created_at']),
            updatedAt: isset($row['updated_at']) && $row['updated_at'] !== null
                ? new DateTimeImmutable($row['updated_at'])
                : null,
        );
    }

    /**
     * Check if operation can still be rolled back
     */
    public function canRollback(): bool
    {
        if ($this->canRollbackUntil === null) {
            return false;
        }

        return new DateTimeImmutable() < $this->canRollbackUntil;
    }

    /**
     * Check if operation is complete
     */
    public function isComplete(): bool
    {
        return in_array($this->status, ['completed', 'failed', 'rolled_back']);
    }

    /**
     * Check if operation is in progress
     */
    public function isRunning(): bool
    {
        return $this->status === 'running';
    }
}
