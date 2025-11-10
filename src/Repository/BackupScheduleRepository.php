<?php

declare(strict_types=1);

namespace PhpBorg\Repository;

use PhpBorg\Entity\BackupSchedule;
use PhpBorg\Database\Connection;
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
        $result = $this->connection->execute(
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
        $row = $this->connection->fetchOne(
            'SELECT * FROM backup_schedules WHERE id = ?',
            [$id]
        );

        return $row ? BackupSchedule::fromDatabase($row) : null;
    }

    /**
     * Find backup schedule by job ID
     */
    public function findByJobId(int $jobId): ?BackupSchedule
    {
        $row = $this->connection->fetchOne(
            'SELECT * FROM backup_schedules WHERE job_id = ?',
            [$jobId]
        );

        return $row ? BackupSchedule::fromDatabase($row) : null;
    }

    /**
     * Find schedules by type
     * 
     * @return BackupSchedule[]
     */
    public function findByType(string $type): array
    {
        $result = $this->connection->execute(
            'SELECT * FROM backup_schedules WHERE type = ? ORDER BY time ASC',
            [$type]
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
        $dailySchedules = $this->connection->execute(
            "SELECT s.* FROM backup_schedules s
             INNER JOIN backup_jobs j ON s.job_id = j.id
             WHERE s.type = 'daily' 
               AND s.time <= ?
               AND j.enabled = 1",
            [$currentTime]
        );

        // Find weekly schedules (using bitmap)
        $weekdayBit = 1 << ($currentDayOfWeek - 1);
        $weeklySchedules = $this->connection->execute(
            "SELECT s.* FROM backup_schedules s
             INNER JOIN backup_jobs j ON s.job_id = j.id
             WHERE s.type = 'weekly' 
               AND (s.weekdays & ?) > 0
               AND s.time <= ?
               AND j.enabled = 1",
            [$weekdayBit, $currentTime]
        );

        // Find monthly schedules (using bitmap)
        $monthdayBit = 1 << ($currentDayOfMonth - 1);
        $monthlySchedules = $this->connection->execute(
            "SELECT s.* FROM backup_schedules s
             INNER JOIN backup_jobs j ON s.job_id = j.id
             WHERE s.type = 'monthly' 
               AND (s.monthdays & ?) > 0
               AND s.time <= ?
               AND j.enabled = 1",
            [$monthdayBit, $currentTime]
        );

        // Find interval schedules
        $intervalSchedules = $this->connection->execute(
            "SELECT s.* FROM backup_schedules s
             INNER JOIN backup_jobs j ON s.job_id = j.id
             WHERE s.type = 'interval' 
               AND j.enabled = 1
               AND (
                   j.next_run_at IS NULL 
                   OR j.next_run_at <= ?
               )",
            [$time->format('Y-m-d H:i:s')]
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
        $this->connection->executeUpdate(
            'INSERT INTO backup_schedules (
                job_id, type, time, timezone, weekdays, monthdays,
                interval_hours, cron_expression, window_start, window_end,
                max_runtime, blackout_periods, retry_on_failure,
                max_retries, retry_delay_minutes, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                $data['job_id'],
                $data['type'],
                $data['time'] ?? '00:00:00',
                $data['timezone'] ?? 'UTC',
                $data['weekdays'] ?? null,
                $data['monthdays'] ?? null,
                $data['interval_hours'] ?? null,
                $data['cron_expression'] ?? null,
                $data['window_start'] ?? null,
                $data['window_end'] ?? null,
                $data['max_runtime'] ?? 14400,
                isset($data['blackout_periods']) ? json_encode($data['blackout_periods']) : null,
                ($data['retry_on_failure'] ?? true) ? 1 : 0,  // Convert boolean to int
                $data['max_retries'] ?? 3,
                $data['retry_delay_minutes'] ?? 30,
            ]
        );

        $id = $this->connection->getLastInsertId();
        
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
        $params = [];

        if (isset($data['type'])) {
            $fields[] = 'type = ?';
            $params[] = $data['type'];
        }

        if (isset($data['time'])) {
            $fields[] = 'time = ?';
            $params[] = $data['time'];
        }

        if (isset($data['timezone'])) {
            $fields[] = 'timezone = ?';
            $params[] = $data['timezone'];
        }

        if (array_key_exists('weekdays', $data)) {
            $fields[] = 'weekdays = ?';
            $params[] = $data['weekdays'];
        }

        if (array_key_exists('monthdays', $data)) {
            $fields[] = 'monthdays = ?';
            $params[] = $data['monthdays'];
        }

        if (array_key_exists('interval_hours', $data)) {
            $fields[] = 'interval_hours = ?';
            $params[] = $data['interval_hours'];
        }

        if (array_key_exists('cron_expression', $data)) {
            $fields[] = 'cron_expression = ?';
            $params[] = $data['cron_expression'];
        }

        if (array_key_exists('window_start', $data)) {
            $fields[] = 'window_start = ?';
            $params[] = $data['window_start'];
        }

        if (array_key_exists('window_end', $data)) {
            $fields[] = 'window_end = ?';
            $params[] = $data['window_end'];
        }

        if (isset($data['max_runtime'])) {
            $fields[] = 'max_runtime = ?';
            $params[] = $data['max_runtime'];
        }

        if (array_key_exists('blackout_periods', $data)) {
            $fields[] = 'blackout_periods = ?';
            $params[] = $data['blackout_periods'] !== null ? json_encode($data['blackout_periods']) : null;
        }

        if (isset($data['retry_on_failure'])) {
            $fields[] = 'retry_on_failure = ?';
            $params[] = $data['retry_on_failure'] ? 1 : 0;  // Convert boolean to int
        }

        if (isset($data['max_retries'])) {
            $fields[] = 'max_retries = ?';
            $params[] = $data['max_retries'];
        }

        if (isset($data['retry_delay_minutes'])) {
            $fields[] = 'retry_delay_minutes = ?';
            $params[] = $data['retry_delay_minutes'];
        }

        if (!empty($fields)) {
            $fields[] = 'updated_at = NOW()';
            $params[] = $id;  // Add id at the end for WHERE clause
            
            $this->connection->executeUpdate(
                'UPDATE backup_schedules SET ' . implode(', ', $fields) . ' WHERE id = ?',
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
        
        $this->connection->executeUpdate(
            'DELETE FROM backup_schedules WHERE id = ?',
            [$id]
        );

        $deleted = $this->connection->affectedRows() > 0;
        
        // Clear job's next_run_at if schedule deleted
        if ($deleted && $schedule) {
            $this->connection->executeUpdate(
                'UPDATE backup_jobs SET next_run_at = NULL WHERE id = ?',
                [$schedule->jobId]
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
        
        $this->connection->executeUpdate(
            'UPDATE backup_jobs SET next_run_at = ? WHERE id = ?',
            [
                $nextRun?->format('Y-m-d H:i:s'),
                $jobId
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