<?php

declare(strict_types=1);

namespace PhpBorg\Entity;

use DateTimeImmutable;

/**
 * phpBorg self-backup entity
 * Represents a complete backup of phpBorg installation (code + DB + config)
 */
final readonly class PhpBorgBackup
{
    public function __construct(
        public int $id,
        public string $filename,
        public string $filepath,
        public int $sizeBytes,
        public bool $encrypted,
        public string $hashSha256,
        public string $phpborgVersion,
        public string $phpVersion,
        public ?string $mysqlVersion,
        public ?string $nodeVersion,
        public ?string $borgVersion,
        public DateTimeImmutable $createdAt,
        public ?int $createdBy,
        public string $backupType,
        public ?string $notes = null,
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
            filename: (string)$row['filename'],
            filepath: (string)$row['filepath'],
            sizeBytes: (int)$row['size_bytes'],
            encrypted: (bool)$row['encrypted'],
            hashSha256: (string)$row['hash_sha256'],
            phpborgVersion: (string)$row['phpborg_version'],
            phpVersion: (string)$row['php_version'],
            mysqlVersion: $row['mysql_version'] ?? null,
            nodeVersion: $row['node_version'] ?? null,
            borgVersion: $row['borg_version'] ?? null,
            createdAt: new DateTimeImmutable($row['created_at']),
            createdBy: isset($row['created_by']) ? (int)$row['created_by'] : null,
            backupType: (string)$row['backup_type'],
            notes: $row['notes'] ?? null,
        );
    }

    /**
     * Convert to array for API responses
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'filename' => $this->filename,
            'filepath' => $this->filepath,
            'size_bytes' => $this->sizeBytes,
            'size_human' => $this->getHumanSize(),
            'encrypted' => $this->encrypted,
            'hash_sha256' => $this->hashSha256,
            'phpborg_version' => $this->phpborgVersion,
            'php_version' => $this->phpVersion,
            'mysql_version' => $this->mysqlVersion,
            'node_version' => $this->nodeVersion,
            'borg_version' => $this->borgVersion,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'created_by' => $this->createdBy,
            'backup_type' => $this->backupType,
            'notes' => $this->notes,
        ];
    }

    /**
     * Get human-readable file size
     */
    public function getHumanSize(): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size = (float)$this->sizeBytes;
        $unitIndex = 0;

        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        return round($size, 2) . ' ' . $units[$unitIndex];
    }

    /**
     * Check if backup file exists on disk
     */
    public function exists(): bool
    {
        return file_exists($this->filepath);
    }

    /**
     * Get backup age in days
     */
    public function getAgeInDays(): int
    {
        $now = new DateTimeImmutable();
        return (int)$now->diff($this->createdAt)->days;
    }

    /**
     * Check if backup is a pre-update backup
     */
    public function isPreUpdate(): bool
    {
        return $this->backupType === 'pre_update';
    }

    /**
     * Check if backup is a manual backup
     */
    public function isManual(): bool
    {
        return $this->backupType === 'manual';
    }

    /**
     * Check if backup is a scheduled backup
     */
    public function isScheduled(): bool
    {
        return $this->backupType === 'scheduled';
    }
}
