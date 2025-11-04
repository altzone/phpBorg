<?php

declare(strict_types=1);

namespace PhpBorg\Entity;

use DateTimeImmutable;

/**
 * Backup report entity
 */
final readonly class Report
{
    public function __construct(
        public int $id,
        public int $serverId,
        public string $type,
        public DateTimeImmutable $start,
        public ?DateTimeImmutable $end,
        public ?string $currentPosition,
        public int $originalSize,
        public int $compressedSize,
        public int $deduplicatedSize,
        public float $duration,
        public int $archiveCount,
        public int $filesCount,
        public bool $hasError,
        public ?string $errorLog,
    ) {
    }

    /**
     * Create report from database row
     *
     * @param array<string, mixed> $row
     */
    public static function fromDatabase(array $row): self
    {
        return new self(
            id: (int)$row['id'],
            serverId: (int)$row['server_id'],
            type: (string)$row['type'],
            start: new DateTimeImmutable($row['start']),
            end: isset($row['end']) && $row['end'] !== null ? new DateTimeImmutable($row['end']) : null,
            currentPosition: $row['curpos'] ?? null,
            originalSize: (int)($row['osize'] ?? 0),
            compressedSize: (int)($row['csize'] ?? 0),
            deduplicatedSize: (int)($row['dsize'] ?? 0),
            duration: (float)($row['dur'] ?? 0),
            archiveCount: (int)($row['nb_archive'] ?? 0),
            filesCount: (int)($row['nfiles'] ?? 0),
            hasError: (bool)($row['error'] ?? false),
            errorLog: $row['log'] ?? null,
        );
    }

    /**
     * Check if backup is still running
     */
    public function isRunning(): bool
    {
        return $this->end === null;
    }

    /**
     * Check if backup completed successfully
     */
    public function isSuccessful(): bool
    {
        return !$this->hasError && $this->end !== null;
    }

    /**
     * Get formatted duration
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
