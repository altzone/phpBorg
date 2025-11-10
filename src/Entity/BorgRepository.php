<?php

declare(strict_types=1);

namespace PhpBorg\Entity;

use DateTimeImmutable;

/**
 * Borg repository entity
 */
final readonly class BorgRepository
{
    public function __construct(
        public int $id,
        public int $serverId,
        public string $repoId,
        public string $type,
        public int $retention, // Legacy field, kept for backward compatibility
        public int $keepDaily,
        public int $keepWeekly,
        public int $keepMonthly,
        public int $keepYearly,
        public string $encryption,
        public string $passphrase,
        public string $repoPath,
        public string $compression,
        public int $rateLimit,
        public string $backupPath,
        public ?string $exclude,
        public int $size,
        public int $compressedSize,
        public int $deduplicatedSize,
        public int $totalUniqueChunks,
        public int $totalChunks,
        public DateTimeImmutable $modified,
    ) {
    }

    /**
     * Create repository from database row
     *
     * @param array<string, mixed> $row
     */
    public static function fromDatabase(array $row): self
    {
        return new self(
            id: (int)$row['id'],
            serverId: (int)$row['server_id'],
            repoId: (string)$row['repo_id'],
            type: (string)$row['type'],
            retention: (int)$row['retention'],
            keepDaily: (int)($row['keep_daily'] ?? $row['retention']),
            keepWeekly: (int)($row['keep_weekly'] ?? 4),
            keepMonthly: (int)($row['keep_monthly'] ?? 6),
            keepYearly: (int)($row['keep_yearly'] ?? 0),
            encryption: (string)$row['encryption'],
            passphrase: (string)$row['passphrase'],
            repoPath: (string)$row['repo_path'],
            compression: (string)$row['compression'],
            rateLimit: (int)$row['ratelimit'],
            backupPath: (string)$row['backup_path'],
            exclude: $row['exclude'] ? (string)$row['exclude'] : null,
            size: (int)($row['size'] ?? 0),
            compressedSize: (int)($row['csize'] ?? 0),
            deduplicatedSize: (int)($row['dsize'] ?? 0),
            totalUniqueChunks: (int)($row['ttuchunks'] ?? 0),
            totalChunks: (int)($row['ttchunks'] ?? 0),
            modified: new DateTimeImmutable($row['modified']),
        );
    }

    /**
     * Get backup paths as array
     *
     * @return array<int, string>
     */
    public function getBackupPaths(): array
    {
        return array_map('trim', explode(',', $this->backupPath));
    }

    /**
     * Get exclusion patterns as array
     *
     * @return array<int, string>
     */
    public function getExclusionPatterns(): array
    {
        if ($this->exclude === null) {
            return [];
        }
        return array_map('trim', explode(',', $this->exclude));
    }

    /**
     * Convert to array for API response
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'server_id' => $this->serverId,
            'repo_id' => $this->repoId,
            'type' => $this->type,
            'retention' => $this->retention,
            'keep_daily' => $this->keepDaily,
            'keep_weekly' => $this->keepWeekly,
            'keep_monthly' => $this->keepMonthly,
            'keep_yearly' => $this->keepYearly,
            'encryption' => $this->encryption,
            'repo_path' => $this->repoPath,
            'compression' => $this->compression,
            'ratelimit' => $this->rateLimit,
            'backup_path' => $this->backupPath,
            'backup_paths' => $this->getBackupPaths(),
            'exclude' => $this->exclude,
            'exclusion_patterns' => $this->getExclusionPatterns(),
            'size' => $this->size,
            'compressed_size' => $this->compressedSize,
            'deduplicated_size' => $this->deduplicatedSize,
            'total_unique_chunks' => $this->totalUniqueChunks,
            'total_chunks' => $this->totalChunks,
            'modified' => $this->modified->format('Y-m-d H:i:s'),
        ];
    }
}
