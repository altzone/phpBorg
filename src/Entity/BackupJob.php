<?php

declare(strict_types=1);

namespace PhpBorg\Entity;

use DateTimeImmutable;

/**
 * Backup job entity for scheduled backups
 */
final readonly class BackupJob
{
    public function __construct(
        public int $id,
        public string $name,
        public int $repositoryId,
        public string $scheduleType, // manual, daily, weekly, monthly, custom
        public ?string $scheduleTime, // HH:MM:SS
        public ?int $scheduleDayOfWeek, // 1-7 (1=Monday)
        public ?int $scheduleDayOfMonth, // 1-31
        public ?string $cronExpression,
        public bool $enabled,
        public bool $notifyOnSuccess,
        public bool $notifyOnFailure,
        public ?DateTimeImmutable $lastRunAt,
        public ?DateTimeImmutable $nextRunAt,
        public ?string $lastStatus, // success, failure, running
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $updatedAt = null,
    ) {
    }

    /**
     * Create BackupJob from database row
     *
     * @param array<string, mixed> $row
     */
    public static function fromDatabase(array $row): self
    {
        return new self(
            id: (int)$row['id'],
            name: (string)$row['name'],
            repositoryId: (int)$row['repository_id'],
            scheduleType: (string)$row['schedule_type'],
            scheduleTime: $row['schedule_time'] ?? null,
            scheduleDayOfWeek: $row['schedule_day_of_week'] !== null ? (int)$row['schedule_day_of_week'] : null,
            scheduleDayOfMonth: $row['schedule_day_of_month'] !== null ? (int)$row['schedule_day_of_month'] : null,
            cronExpression: $row['cron_expression'] ?? null,
            enabled: (bool)$row['enabled'],
            notifyOnSuccess: (bool)($row['notify_on_success'] ?? false),
            notifyOnFailure: (bool)($row['notify_on_failure'] ?? true),
            lastRunAt: isset($row['last_run_at']) && $row['last_run_at'] !== null
                ? new DateTimeImmutable($row['last_run_at'])
                : null,
            nextRunAt: isset($row['next_run_at']) && $row['next_run_at'] !== null
                ? new DateTimeImmutable($row['next_run_at'])
                : null,
            lastStatus: $row['last_status'] ?? null,
            createdAt: new DateTimeImmutable($row['created_at']),
            updatedAt: isset($row['updated_at']) && $row['updated_at'] !== null
                ? new DateTimeImmutable($row['updated_at'])
                : null,
        );
    }

    /**
     * Check if job is a scheduled job (not manual)
     */
    public function isScheduled(): bool
    {
        return $this->scheduleType !== 'manual';
    }

    /**
     * Check if job should run now
     */
    public function shouldRunNow(): bool
    {
        if (!$this->enabled || !$this->isScheduled()) {
            return false;
        }

        if ($this->nextRunAt === null) {
            return false;
        }

        $now = new DateTimeImmutable();
        return $this->nextRunAt <= $now;
    }

    /**
     * Get human-readable schedule description
     */
    public function getScheduleDescription(): string
    {
        return match ($this->scheduleType) {
            'manual' => 'Manual only',
            'daily' => 'Daily at ' . ($this->scheduleTime ?? '00:00'),
            'weekly' => sprintf('Weekly on %s at %s',
                $this->getDayName($this->scheduleDayOfWeek ?? 1),
                $this->scheduleTime ?? '00:00'
            ),
            'monthly' => sprintf('Monthly on day %d at %s',
                $this->scheduleDayOfMonth ?? 1,
                $this->scheduleTime ?? '00:00'
            ),
            'custom' => 'Custom: ' . ($this->cronExpression ?? 'Not set'),
            default => 'Unknown',
        };
    }

    /**
     * Get day name from day number
     */
    private function getDayName(int $day): string
    {
        return match ($day) {
            1 => 'Monday',
            2 => 'Tuesday',
            3 => 'Wednesday',
            4 => 'Thursday',
            5 => 'Friday',
            6 => 'Saturday',
            7 => 'Sunday',
            default => 'Unknown',
        };
    }

    /**
     * Convert to array for API response
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'repository_id' => $this->repositoryId,
            'schedule_type' => $this->scheduleType,
            'schedule_time' => $this->scheduleTime,
            'schedule_day_of_week' => $this->scheduleDayOfWeek,
            'schedule_day_of_month' => $this->scheduleDayOfMonth,
            'cron_expression' => $this->cronExpression,
            'schedule_description' => $this->getScheduleDescription(),
            'enabled' => $this->enabled,
            'notify_on_success' => $this->notifyOnSuccess,
            'notify_on_failure' => $this->notifyOnFailure,
            'last_run_at' => $this->lastRunAt?->format('Y-m-d H:i:s'),
            'next_run_at' => $this->nextRunAt?->format('Y-m-d H:i:s'),
            'last_status' => $this->lastStatus,
            'is_scheduled' => $this->isScheduled(),
            'should_run_now' => $this->shouldRunNow(),
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt?->format('Y-m-d H:i:s'),
        ];
    }
}
