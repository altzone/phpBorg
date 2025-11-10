<?php

declare(strict_types=1);

namespace PhpBorg\Entity;

use DateTimeImmutable;
use DateTime;

/**
 * Backup schedule entity - advanced scheduling configuration
 */
final readonly class BackupSchedule
{
    // Weekday bitmask constants
    public const MONDAY = 1;
    public const TUESDAY = 2;
    public const WEDNESDAY = 4;
    public const THURSDAY = 8;
    public const FRIDAY = 16;
    public const SATURDAY = 32;
    public const SUNDAY = 64;
    
    public function __construct(
        public int $id,
        public int $jobId,
        public string $type, // interval, daily, weekly, monthly, cron, advanced
        public string $time, // HH:MM:SS
        public string $timezone,
        public ?int $weekdays, // Bitmap for selected weekdays
        public ?int $monthdays, // Bitmap for selected month days
        public ?int $intervalHours,
        public ?string $cronExpression,
        public ?string $windowStart,
        public ?string $windowEnd,
        public int $maxRuntime, // seconds
        public ?array $blackoutPeriods,
        public bool $retryOnFailure,
        public int $maxRetries,
        public int $retryDelayMinutes,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $updatedAt = null,
    ) {
    }

    /**
     * Create BackupSchedule from database row
     *
     * @param array<string, mixed> $row
     */
    public static function fromDatabase(array $row): self
    {
        return new self(
            id: (int)$row['id'],
            jobId: (int)$row['job_id'],
            type: (string)$row['type'],
            time: (string)$row['time'],
            timezone: $row['timezone'] ?? 'UTC',
            weekdays: isset($row['weekdays']) ? (int)$row['weekdays'] : null,
            monthdays: isset($row['monthdays']) ? (int)$row['monthdays'] : null,
            intervalHours: isset($row['interval_hours']) ? (int)$row['interval_hours'] : null,
            cronExpression: $row['cron_expression'] ?? null,
            windowStart: $row['window_start'] ?? null,
            windowEnd: $row['window_end'] ?? null,
            maxRuntime: (int)($row['max_runtime'] ?? 14400),
            blackoutPeriods: isset($row['blackout_periods']) && $row['blackout_periods'] !== null
                ? json_decode($row['blackout_periods'], true)
                : null,
            retryOnFailure: (bool)($row['retry_on_failure'] ?? true),
            maxRetries: (int)($row['max_retries'] ?? 3),
            retryDelayMinutes: (int)($row['retry_delay_minutes'] ?? 30),
            createdAt: new DateTimeImmutable($row['created_at']),
            updatedAt: isset($row['updated_at']) && $row['updated_at'] !== null
                ? new DateTimeImmutable($row['updated_at'])
                : null,
        );
    }

    /**
     * Get selected weekdays as array
     */
    public function getSelectedWeekdays(): array
    {
        if ($this->weekdays === null) {
            return [];
        }

        $days = [];
        $dayMap = [
            self::MONDAY => 'Monday',
            self::TUESDAY => 'Tuesday',
            self::WEDNESDAY => 'Wednesday',
            self::THURSDAY => 'Thursday',
            self::FRIDAY => 'Friday',
            self::SATURDAY => 'Saturday',
            self::SUNDAY => 'Sunday',
        ];

        foreach ($dayMap as $bit => $name) {
            if ($this->weekdays & $bit) {
                $days[] = $name;
            }
        }

        return $days;
    }

    /**
     * Get selected month days as array
     */
    public function getSelectedMonthDays(): array
    {
        if ($this->monthdays === null) {
            return [];
        }

        $days = [];
        for ($i = 1; $i <= 31; $i++) {
            if ($this->monthdays & (1 << ($i - 1))) {
                $days[] = $i;
            }
        }

        return $days;
    }

    /**
     * Check if schedule is active on a specific weekday
     */
    public function isActiveOnWeekday(int $dayOfWeek): bool
    {
        if ($this->type !== 'weekly' || $this->weekdays === null) {
            return false;
        }

        $bit = match ($dayOfWeek) {
            1 => self::MONDAY,
            2 => self::TUESDAY,
            3 => self::WEDNESDAY,
            4 => self::THURSDAY,
            5 => self::FRIDAY,
            6 => self::SATURDAY,
            7 => self::SUNDAY,
            default => 0,
        };

        return ($this->weekdays & $bit) !== 0;
    }

    /**
     * Check if current time is within backup window
     */
    public function isWithinWindow(DateTimeImmutable $time): bool
    {
        if ($this->windowStart === null || $this->windowEnd === null) {
            return true; // No window defined, always valid
        }

        $currentTime = $time->format('H:i:s');
        
        // Handle window that crosses midnight
        if ($this->windowEnd < $this->windowStart) {
            return $currentTime >= $this->windowStart || $currentTime <= $this->windowEnd;
        }
        
        return $currentTime >= $this->windowStart && $currentTime <= $this->windowEnd;
    }

    /**
     * Check if date is in blackout period
     */
    public function isInBlackoutPeriod(DateTimeImmutable $date): bool
    {
        if ($this->blackoutPeriods === null || empty($this->blackoutPeriods)) {
            return false;
        }

        foreach ($this->blackoutPeriods as $period) {
            if (isset($period['start']) && isset($period['end'])) {
                $start = new DateTimeImmutable($period['start']);
                $end = new DateTimeImmutable($period['end']);
                
                if ($date >= $start && $date <= $end) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Calculate next run time based on schedule
     */
    public function calculateNextRun(?DateTimeImmutable $from = null): ?DateTimeImmutable
    {
        $from = $from ?? new DateTimeImmutable();
        
        return match ($this->type) {
            'interval' => $this->calculateIntervalNextRun($from),
            'daily' => $this->calculateDailyNextRun($from),
            'weekly' => $this->calculateWeeklyNextRun($from),
            'monthly' => $this->calculateMonthlyNextRun($from),
            'cron' => $this->calculateCronNextRun($from),
            default => null,
        };
    }

    private function calculateIntervalNextRun(DateTimeImmutable $from): ?DateTimeImmutable
    {
        if ($this->intervalHours === null || $this->intervalHours <= 0) {
            return null;
        }

        return $from->modify("+{$this->intervalHours} hours");
    }

    private function calculateDailyNextRun(DateTimeImmutable $from): DateTimeImmutable
    {
        $next = $from->setTime(
            (int)substr($this->time, 0, 2),
            (int)substr($this->time, 3, 2),
            (int)substr($this->time, 6, 2)
        );

        if ($next <= $from) {
            $next = $next->modify('+1 day');
        }

        // Skip blackout periods
        while ($this->isInBlackoutPeriod($next)) {
            $next = $next->modify('+1 day');
        }

        return $next;
    }

    private function calculateWeeklyNextRun(DateTimeImmutable $from): ?DateTimeImmutable
    {
        if ($this->weekdays === null || $this->weekdays === 0) {
            return null;
        }

        $next = $from->setTime(
            (int)substr($this->time, 0, 2),
            (int)substr($this->time, 3, 2),
            (int)substr($this->time, 6, 2)
        );

        // Find next active weekday
        for ($i = 0; $i < 14; $i++) { // Check up to 2 weeks ahead
            if ($i > 0 || $next <= $from) {
                $next = $next->modify('+1 day');
            }
            
            $dayOfWeek = (int)$next->format('N');
            if ($this->isActiveOnWeekday($dayOfWeek) && !$this->isInBlackoutPeriod($next)) {
                return $next;
            }
        }

        return null;
    }

    private function calculateMonthlyNextRun(DateTimeImmutable $from): ?DateTimeImmutable
    {
        if ($this->monthdays === null || $this->monthdays === 0) {
            return null;
        }

        $selectedDays = $this->getSelectedMonthDays();
        if (empty($selectedDays)) {
            return null;
        }

        $next = $from->setTime(
            (int)substr($this->time, 0, 2),
            (int)substr($this->time, 3, 2),
            (int)substr($this->time, 6, 2)
        );

        $currentDay = (int)$from->format('d');
        $currentMonth = $from->format('Y-m');

        // Find next valid day in current month
        foreach ($selectedDays as $day) {
            if ($day > $currentDay || ($day === $currentDay && $next > $from)) {
                $candidate = new DateTimeImmutable("$currentMonth-$day " . $this->time);
                if (!$this->isInBlackoutPeriod($candidate)) {
                    return $candidate;
                }
            }
        }

        // Try next month
        $nextMonth = $from->modify('+1 month')->format('Y-m');
        foreach ($selectedDays as $day) {
            $candidate = new DateTimeImmutable("$nextMonth-$day " . $this->time);
            if (!$this->isInBlackoutPeriod($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function calculateCronNextRun(DateTimeImmutable $from): ?DateTimeImmutable
    {
        // TODO: Implement cron expression parser
        // For now, return null - this would need a proper cron library
        return null;
    }

    /**
     * Get human-readable schedule description
     */
    public function getDescription(): string
    {
        return match ($this->type) {
            'interval' => "Every {$this->intervalHours} hours",
            'daily' => "Daily at {$this->time}",
            'weekly' => sprintf("Weekly on %s at %s", 
                implode(', ', $this->getSelectedWeekdays()),
                $this->time
            ),
            'monthly' => sprintf("Monthly on days %s at %s",
                implode(', ', $this->getSelectedMonthDays()),
                $this->time
            ),
            'cron' => "Cron: {$this->cronExpression}",
            'advanced' => 'Advanced schedule',
            default => 'Unknown schedule',
        };
    }

    /**
     * Convert to array for API response
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'job_id' => $this->jobId,
            'type' => $this->type,
            'time' => $this->time,
            'timezone' => $this->timezone,
            'weekdays' => $this->weekdays,
            'weekdays_selected' => $this->getSelectedWeekdays(),
            'monthdays' => $this->monthdays,
            'monthdays_selected' => $this->getSelectedMonthDays(),
            'interval_hours' => $this->intervalHours,
            'cron_expression' => $this->cronExpression,
            'window_start' => $this->windowStart,
            'window_end' => $this->windowEnd,
            'max_runtime' => $this->maxRuntime,
            'blackout_periods' => $this->blackoutPeriods,
            'retry_on_failure' => $this->retryOnFailure,
            'max_retries' => $this->maxRetries,
            'retry_delay_minutes' => $this->retryDelayMinutes,
            'description' => $this->getDescription(),
            'next_run' => $this->calculateNextRun()?->format('Y-m-d H:i:s'),
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt?->format('Y-m-d H:i:s'),
        ];
    }
}