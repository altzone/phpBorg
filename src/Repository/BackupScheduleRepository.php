<?php

declare(strict_types=1);

namespace PhpBorg\Repository;

use PhpBorg\Entity\BackupSchedule;
use PhpBorg\Infrastructure\Database\Connection;
use DateTimeImmutable;

/**
 * Repository for backup schedules
 */
class BackupScheduleRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * Find all backup schedules
     * 
     * @return BackupSchedule[]
     */
    public function findAll(): array
    {
        $result = $this->connection->query(
            'SELECT * FROM backup_schedules ORDER BY job_id ASC'
        );

        $schedules = [];
        foreach ($result as $row) {
            $schedules[] = BackupSchedule::fromDatabase($row);
        }

        return $schedules;
    }

    /**
     * Find backup schedule by ID
     */
    public function findById(int $id): ?BackupSchedule
    {
        $result = $this->connection->query(
            'SELECT * FROM backup_schedules WHERE id = :id',
            ['id' => $id]
        );

        $row = $result->fetch();
        return $row ? BackupSchedule::fromDatabase($row) : null;
    }

    /**
     * Find backup schedule by job ID
     */
    public function findByJobId(int $jobId): ?BackupSchedule
    {
        $result = $this->connection->query(
            'SELECT * FROM backup_schedules WHERE job_id = :job_id',
            ['job_id' => $jobId]
        );

        $row = $result->fetch();
        return $row ? BackupSchedule::fromDatabase($row) : null;
    }

    /**
     * Find schedules by type
     * 
     * @return BackupSchedule[]
     */
    public function findByType(string $type): array
    {
        $result = $this->connection->query(
            'SELECT * FROM backup_schedules WHERE type = :type ORDER BY time ASC',
            ['type' => $type]
        );

        $schedules = [];
        foreach ($result as $row) {
            $schedules[] = BackupSchedule::fromDatabase($row);
        }

        return $schedules;
    }

    /**
     * Find schedules that should run at a specific time
     * 
     * @return BackupSchedule[]
     */
    public function findSchedulesToRun(DateTimeImmutable $time): array
    {
        $currentTime = $time->format('H:i:s');
        $currentDayOfWeek = (int)$time->format('N');
        $currentDayOfMonth = (int)$time->format('d');
        
        // Find daily schedules
        $dailySchedules = $this->connection->query(
            "SELECT s.* FROM backup_schedules s
             INNER JOIN backup_jobs j ON s.job_id = j.id
             WHERE s.type = 'daily' 
               AND s.time <= :current_time
               AND j.enabled = 1",
            ['current_time' => $currentTime]
        );

        // Find weekly schedules (using bitmap)
        $weekdayBit = 1 << ($currentDayOfWeek - 1);
        $weeklySchedules = $this->connection->query(
            "SELECT s.* FROM backup_schedules s
             INNER JOIN backup_jobs j ON s.job_id = j.id
             WHERE s.type = 'weekly' 
               AND (s.weekdays & :weekday_bit) > 0
               AND s.time <= :current_time
               AND j.enabled = 1",
            [
                'weekday_bit' => $weekdayBit,
                'current_time' => $currentTime
            ]
        );

        // Find monthly schedules (using bitmap)
        $monthdayBit = 1 << ($currentDayOfMonth - 1);
        $monthlySchedules = $this->connection->query(
            "SELECT s.* FROM backup_schedules s
             INNER JOIN backup_jobs j ON s.job_id = j.id
             WHERE s.type = 'monthly' 
               AND (s.monthdays & :monthday_bit) > 0
               AND s.time <= :current_time
               AND j.enabled = 1",
            [
                'monthday_bit' => $monthdayBit,
                'current_time' => $currentTime
            ]
        );

        // Find interval schedules
        $intervalSchedules = $this->connection->query(
            "SELECT s.* FROM backup_schedules s
             INNER JOIN backup_jobs j ON s.job_id = j.id
             WHERE s.type = 'interval' 
               AND j.enabled = 1
               AND (
                   j.next_run_at IS NULL 
                   OR j.next_run_at <= :current_datetime
               )",
            ['current_datetime' => $time->format('Y-m-d H:i:s')]
        );

        $schedules = [];
        
        foreach ($dailySchedules as $row) {
            $schedule = BackupSchedule::fromDatabase($row);
            if ($this->shouldRun($schedule, $time)) {
                $schedules[] = $schedule;
            }
        }

        foreach ($weeklySchedules as $row) {
            $schedule = BackupSchedule::fromDatabase($row);
            if ($this->shouldRun($schedule, $time)) {
                $schedules[] = $schedule;
            }
        }

        foreach ($monthlySchedules as $row) {
            $schedule = BackupSchedule::fromDatabase($row);
            if ($this->shouldRun($schedule, $time)) {
                $schedules[] = $schedule;
            }
        }

        foreach ($intervalSchedules as $row) {
            $schedule = BackupSchedule::fromDatabase($row);
            if ($this->shouldRun($schedule, $time)) {
                $schedules[] = $schedule;
            }
        }

        return $schedules;
    }

    /**
     * Check if schedule should run considering windows and blackouts
     */
    private function shouldRun(BackupSchedule $schedule, DateTimeImmutable $time): bool
    {
        // Check if within backup window
        if (!$schedule->isWithinWindow($time)) {
            return false;
        }

        // Check if in blackout period
        if ($schedule->isInBlackoutPeriod($time)) {
            return false;
        }

        return true;
    }

    /**
     * Create a new backup schedule
     */
    public function create(array $data): BackupSchedule
    {
        $this->connection->execute(
            'INSERT INTO backup_schedules (
                job_id, type, time, timezone, weekdays, monthdays,
                interval_hours, cron_expression, window_start, window_end,
                max_runtime, blackout_periods, retry_on_failure,
                max_retries, retry_delay_minutes, created_at
            ) VALUES (
                :job_id, :type, :time, :timezone, :weekdays, :monthdays,
                :interval_hours, :cron_expression, :window_start, :window_end,
                :max_runtime, :blackout_periods, :retry_on_failure,
                :max_retries, :retry_delay_minutes, NOW()
            )',
            [
                'job_id' => $data['job_id'],
                'type' => $data['type'],
                'time' => $data['time'] ?? '00:00:00',
                'timezone' => $data['timezone'] ?? 'UTC',
                'weekdays' => $data['weekdays'] ?? null,
                'monthdays' => $data['monthdays'] ?? null,
                'interval_hours' => $data['interval_hours'] ?? null,
                'cron_expression' => $data['cron_expression'] ?? null,
                'window_start' => $data['window_start'] ?? null,
                'window_end' => $data['window_end'] ?? null,
                'max_runtime' => $data['max_runtime'] ?? 14400,
                'blackout_periods' => isset($data['blackout_periods']) ? json_encode($data['blackout_periods']) : null,
                'retry_on_failure' => $data['retry_on_failure'] ?? true,
                'max_retries' => $data['max_retries'] ?? 3,
                'retry_delay_minutes' => $data['retry_delay_minutes'] ?? 30,
            ]
        );

        $id = $this->connection->lastInsertId();
        
        // Update job's next_run_at
        $schedule = $this->findById($id);
        if ($schedule) {
            $this->updateJobNextRun($schedule->jobId, $schedule);
        }
        
        return $schedule;
    }

    /**
     * Update a backup schedule
     */
    public function update(int $id, array $data): BackupSchedule
    {
        $fields = [];
        $params = ['id' => $id];

        if (isset($data['type'])) {
            $fields[] = 'type = :type';
            $params['type'] = $data['type'];
        }

        if (isset($data['time'])) {
            $fields[] = 'time = :time';
            $params['time'] = $data['time'];
        }

        if (isset($data['timezone'])) {
            $fields[] = 'timezone = :timezone';
            $params['timezone'] = $data['timezone'];
        }

        if (array_key_exists('weekdays', $data)) {
            $fields[] = 'weekdays = :weekdays';
            $params['weekdays'] = $data['weekdays'];
        }

        if (array_key_exists('monthdays', $data)) {
            $fields[] = 'monthdays = :monthdays';
            $params['monthdays'] = $data['monthdays'];
        }

        if (array_key_exists('interval_hours', $data)) {
            $fields[] = 'interval_hours = :interval_hours';
            $params['interval_hours'] = $data['interval_hours'];
        }

        if (array_key_exists('cron_expression', $data)) {
            $fields[] = 'cron_expression = :cron_expression';
            $params['cron_expression'] = $data['cron_expression'];
        }

        if (array_key_exists('window_start', $data)) {
            $fields[] = 'window_start = :window_start';
            $params['window_start'] = $data['window_start'];
        }

        if (array_key_exists('window_end', $data)) {
            $fields[] = 'window_end = :window_end';
            $params['window_end'] = $data['window_end'];
        }

        if (isset($data['max_runtime'])) {
            $fields[] = 'max_runtime = :max_runtime';
            $params['max_runtime'] = $data['max_runtime'];
        }

        if (array_key_exists('blackout_periods', $data)) {
            $fields[] = 'blackout_periods = :blackout_periods';
            $params['blackout_periods'] = $data['blackout_periods'] !== null ? json_encode($data['blackout_periods']) : null;
        }

        if (isset($data['retry_on_failure'])) {
            $fields[] = 'retry_on_failure = :retry_on_failure';
            $params['retry_on_failure'] = $data['retry_on_failure'];
        }

        if (isset($data['max_retries'])) {
            $fields[] = 'max_retries = :max_retries';
            $params['max_retries'] = $data['max_retries'];
        }

        if (isset($data['retry_delay_minutes'])) {
            $fields[] = 'retry_delay_minutes = :retry_delay_minutes';
            $params['retry_delay_minutes'] = $data['retry_delay_minutes'];
        }

        if (!empty($fields)) {
            $fields[] = 'updated_at = NOW()';
            
            $this->connection->execute(
                'UPDATE backup_schedules SET ' . implode(', ', $fields) . ' WHERE id = :id',
                $params
            );
        }

        $schedule = $this->findById($id);
        if ($schedule) {
            $this->updateJobNextRun($schedule->jobId, $schedule);
        }
        
        return $schedule;
    }

    /**
     * Delete a backup schedule
     */
    public function delete(int $id): bool
    {
        // Get job_id before deletion
        $schedule = $this->findById($id);
        
        $this->connection->execute(
            'DELETE FROM backup_schedules WHERE id = :id',
            ['id' => $id]
        );

        $deleted = $this->connection->affectedRows() > 0;
        
        // Clear job's next_run_at if schedule deleted
        if ($deleted && $schedule) {
            $this->connection->execute(
                'UPDATE backup_jobs SET next_run_at = NULL WHERE id = :job_id',
                ['job_id' => $schedule->jobId]
            );
        }

        return $deleted;
    }

    /**
     * Update job's next_run_at based on schedule
     */
    private function updateJobNextRun(int $jobId, BackupSchedule $schedule): void
    {
        $nextRun = $schedule->calculateNextRun();
        
        $this->connection->execute(
            'UPDATE backup_jobs SET next_run_at = :next_run WHERE id = :job_id',
            [
                'job_id' => $jobId,
                'next_run' => $nextRun?->format('Y-m-d H:i:s')
            ]
        );
    }

    /**
     * Calculate weekdays bitmap from array of day numbers
     * 
     * @param array<int> $days Array of day numbers (1=Monday, 7=Sunday)
     */
    public function calculateWeekdaysBitmap(array $days): int
    {
        $bitmap = 0;
        foreach ($days as $day) {
            if ($day >= 1 && $day <= 7) {
                $bitmap |= (1 << ($day - 1));
            }
        }
        return $bitmap;
    }

    /**
     * Calculate monthdays bitmap from array of day numbers
     * 
     * @param array<int> $days Array of day numbers (1-31)
     */
    public function calculateMonthdaysBitmap(array $days): int
    {
        $bitmap = 0;
        foreach ($days as $day) {
            if ($day >= 1 && $day <= 31) {
                $bitmap |= (1 << ($day - 1));
            }
        }
        return $bitmap;
    }
}