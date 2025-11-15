<?php

declare(strict_types=1);

namespace PhpBorg\Entity;

use DateTime;

/**
 * Instant Recovery Session entity
 * Represents an active database instance mounted from a backup
 */
final readonly class InstantRecoverySession
{
    public function __construct(
        public int $id,
        public int $archiveId,
        public int $serverId,
        public string $dbType,
        public string $deploymentLocation, // 'remote' or 'local'
        public string $status,
        public string $borgMountPoint,
        public string $overlayUpperDir,
        public string $overlayWorkDir,
        public string $overlayMergedDir,
        public int $dbPort,
        public ?int $dbPid,
        public ?string $dbSocket,
        public ?string $connectionString,
        public DateTime $createdAt,
        public ?DateTime $startedAt = null,
        public ?DateTime $stoppedAt = null,
        public ?string $errorMessage = null,
    ) {
    }

    /**
     * Create from database row
     *
     * @param array<string, mixed> $row
     */
    public static function fromDatabase(array $row): self
    {
        return new self(
            id: (int)$row['id'],
            archiveId: (int)$row['archive_id'],
            serverId: (int)$row['server_id'],
            dbType: (string)$row['db_type'],
            deploymentLocation: (string)($row['deployment_location'] ?? 'remote'),
            status: (string)$row['status'],
            borgMountPoint: (string)$row['borg_mount_point'],
            overlayUpperDir: (string)$row['overlay_upper_dir'],
            overlayWorkDir: (string)$row['overlay_work_dir'],
            overlayMergedDir: (string)$row['overlay_merged_dir'],
            dbPort: (int)$row['db_port'],
            dbPid: isset($row['db_pid']) ? (int)$row['db_pid'] : null,
            dbSocket: $row['db_socket'] ?? null,
            connectionString: $row['connection_string'] ?? null,
            createdAt: new DateTime($row['created_at']),
            startedAt: !empty($row['started_at']) ? new DateTime($row['started_at']) : null,
            stoppedAt: !empty($row['stopped_at']) ? new DateTime($row['stopped_at']) : null,
            errorMessage: $row['error_message'] ?? null,
        );
    }

    /**
     * Convert to array for API response
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'archive_id' => $this->archiveId,
            'server_id' => $this->serverId,
            'db_type' => $this->dbType,
            'deployment_location' => $this->deploymentLocation,
            'status' => $this->status,
            'borg_mount_point' => $this->borgMountPoint,
            'overlay_upper_dir' => $this->overlayUpperDir,
            'overlay_work_dir' => $this->overlayWorkDir,
            'overlay_merged_dir' => $this->overlayMergedDir,
            'db_port' => $this->dbPort,
            'db_pid' => $this->dbPid,
            'db_socket' => $this->dbSocket,
            'connection_string' => $this->connectionString,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'started_at' => $this->startedAt?->format('Y-m-d H:i:s'),
            'stopped_at' => $this->stoppedAt?->format('Y-m-d H:i:s'),
            'error_message' => $this->errorMessage,
        ];
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['starting', 'active']);
    }
}
