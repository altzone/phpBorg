<?php

declare(strict_types=1);

namespace PhpBorg\Entity;

use DateTimeImmutable;

/**
 * Backup archive entity
 */
final readonly class Archive
{
    public function __construct(
        public int $id,
        public string $repoId,
        public string $name,
        public string $archiveId,
        public float $duration,
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
        public int $compressedSize,
        public int $deduplicatedSize,
        public int $originalSize,
        public int $filesCount,
    ) {
    }

    /**
     * Create archive from database row
     *
     * @param array<string, mixed> $row
     */
    public static function fromDatabase(array $row): self
    {
        return new self(
            id: (int)$row['id'],
            repoId: (string)$row['repo_id'],
            name: (string)$row['nom'],
            archiveId: (string)$row['archive_id'],
            duration: (float)$row['dur'],
            start: new DateTimeImmutable($row['start']),
            end: new DateTimeImmutable($row['end']),
            compressedSize: (int)$row['csize'],
            deduplicatedSize: (int)$row['dsize'],
            originalSize: (int)$row['osize'],
            filesCount: (int)$row['nfiles'],
        );
    }

    /**
     * Get compression ratio as percentage
     */
    public function getCompressionRatio(): float
    {
        if ($this->originalSize === 0) {
            return 0.0;
        }
        return round((1 - ($this->compressedSize / $this->originalSize)) * 100, 2);
    }

    /**
     * Get deduplication ratio as percentage
     */
    public function getDeduplicationRatio(): float
    {
        if ($this->originalSize === 0) {
            return 0.0;
        }
        return round((1 - ($this->deduplicatedSize / $this->originalSize)) * 100, 2);
    }

    /**
     * Format duration as human-readable string
     */
    public function getFormattedDuration(): string
    {
        $seconds = (int)$this->duration;
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%dh %dm %ds', $hours, $minutes, $secs);
        }
        if ($minutes > 0) {
            return sprintf('%dm %ds', $minutes, $secs);
        }
        return sprintf('%ds', $secs);
    }
}
