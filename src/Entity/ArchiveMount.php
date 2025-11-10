<?php

declare(strict_types=1);

namespace PhpBorg\Entity;

use DateTimeImmutable;

/**
 * Archive mount entity
 * Represents a mounted Borg archive for browsing/restore
 */
final readonly class ArchiveMount
{
    public function __construct(
        public int $id,
        public int $archiveId,
        public string $mountPath,
        public string $status, // mounting, mounted, unmounting, error
        public DateTimeImmutable $mountedAt,
        public DateTimeImmutable $lastAccess,
        public ?string $errorMessage = null,
    ) {
    }

    /**
     * Create ArchiveMount from database row
     *
     * @param array<string, mixed> $row
     */
    public static function fromDatabase(array $row): self
    {
        return new self(
            id: (int)$row['id'],
            archiveId: (int)$row['archive_id'],
            mountPath: (string)$row['mount_path'],
            status: (string)$row['status'],
            mountedAt: new DateTimeImmutable($row['mounted_at']),
            lastAccess: new DateTimeImmutable($row['last_access']),
            errorMessage: $row['error_message'] ?? null,
        );
    }

    /**
     * Check if mount is active
     */
    public function isActive(): bool
    {
        return $this->status === 'mounted';
    }

    /**
     * Check if mount is stale (inactive for more than X minutes)
     */
    public function isStale(int $timeoutMinutes = 15): bool
    {
        $now = new DateTimeImmutable();
        $diff = $now->getTimestamp() - $this->lastAccess->getTimestamp();
        return $diff > ($timeoutMinutes * 60);
    }

    /**
     * Get age in minutes since last access
     */
    public function getIdleMinutes(): int
    {
        $now = new DateTimeImmutable();
        return (int)(($now->getTimestamp() - $this->lastAccess->getTimestamp()) / 60);
    }
}
