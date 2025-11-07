<?php

declare(strict_types=1);

namespace PhpBorg\Repository;

use PhpBorg\Database\Connection;
use PhpBorg\Entity\BackupJob;
use PhpBorg\Exception\DatabaseException;
use DateTimeImmutable;

/**
 * Repository for BackupJob entities
 */
final class BackupJobRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * Find backup job by ID
     *
     * @throws DatabaseException
     */
    public function findById(int $id): ?BackupJob
    {
        $row = $this->connection->fetchOne(
            'SELECT * FROM backup_jobs WHERE id = ?',
            [$id]
        );

        return $row ? BackupJob::fromDatabase($row) : null;
    }

    /**
     * Find all backup jobs
     *
     * @return array<int, BackupJob>
     * @throws DatabaseException
     */
    public function findAll(): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT * FROM backup_jobs ORDER BY name'
        );

        return array_map(fn(array $row) => BackupJob::fromDatabase($row), $rows);
    }

    /**
     * Find jobs by repository
     *
     * @return array<int, BackupJob>
     * @throws DatabaseException
     */
    public function findByRepositoryId(int $repositoryId): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT * FROM backup_jobs WHERE repository_id = ? ORDER BY name',
            [$repositoryId]
        );

        return array_map(fn(array $row) => BackupJob::fromDatabase($row), $rows);
    }

    /**
     * Find enabled jobs
     *
     * @return array<int, BackupJob>
     * @throws DatabaseException
     */
    public function findEnabled(): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT * FROM backup_jobs WHERE enabled = 1 ORDER BY next_run_at ASC'
        );

        return array_map(fn(array $row) => BackupJob::fromDatabase($row), $rows);
    }

    /**
     * Find jobs that should run now
     *
     * @return array<int, BackupJob>
     * @throws DatabaseException
     */
    public function findJobsToRun(): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT * FROM backup_jobs
             WHERE enabled = 1
             AND schedule_type != "manual"
             AND next_run_at IS NOT NULL
             AND next_run_at <= NOW()
             ORDER BY next_run_at ASC'
        );

        return array_map(fn(array $row) => BackupJob::fromDatabase($row), $rows);
    }

    /**
     * Create new backup job
     *
     * @throws DatabaseException
     */
    public function create(
        string $name,
        int $repositoryId,
        string $scheduleType,
        ?string $scheduleTime = null,
        ?int $scheduleDayOfWeek = null,
        ?int $scheduleDayOfMonth = null,
        ?string $cronExpression = null,
        bool $enabled = true,
        bool $notifyOnSuccess = false,
        bool $notifyOnFailure = true
    ): int {
        // Calculate next run if scheduled
        $nextRunAt = $this->calculateNextRun(
            $scheduleType,
            $scheduleTime,
            $scheduleDayOfWeek,
            $scheduleDayOfMonth,
            $cronExpression
        );

        $this->connection->executeUpdate(
            'INSERT INTO backup_jobs (
                name, repository_id, schedule_type, schedule_time,
                schedule_day_of_week, schedule_day_of_month, cron_expression,
                enabled, notify_on_success, notify_on_failure, next_run_at, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                $name, $repositoryId, $scheduleType, $scheduleTime,
                $scheduleDayOfWeek, $scheduleDayOfMonth, $cronExpression,
                $enabled ? 1 : 0, $notifyOnSuccess ? 1 : 0, $notifyOnFailure ? 1 : 0,
                $nextRunAt
            ]
        );

        return $this->connection->getLastInsertId();
    }

    /**
     * Update backup job
     *
     * @throws DatabaseException
     */
    public function update(
        int $id,
        string $name,
        string $scheduleType,
        ?string $scheduleTime,
        ?int $scheduleDayOfWeek,
        ?int $scheduleDayOfMonth,
        ?string $cronExpression,
        bool $enabled,
        bool $notifyOnSuccess,
        bool $notifyOnFailure
    ): void {
        // Recalculate next run
        $nextRunAt = $this->calculateNextRun(
            $scheduleType,
            $scheduleTime,
            $scheduleDayOfWeek,
            $scheduleDayOfMonth,
            $cronExpression
        );

        $this->connection->executeUpdate(
            'UPDATE backup_jobs SET
                name = ?, schedule_type = ?, schedule_time = ?,
                schedule_day_of_week = ?, schedule_day_of_month = ?, cron_expression = ?,
                enabled = ?, notify_on_success = ?, notify_on_failure = ?,
                next_run_at = ?, updated_at = NOW()
             WHERE id = ?',
            [
                $name, $scheduleType, $scheduleTime,
                $scheduleDayOfWeek, $scheduleDayOfMonth, $cronExpression,
                $enabled ? 1 : 0, $notifyOnSuccess ? 1 : 0, $notifyOnFailure ? 1 : 0,
                $nextRunAt, $id
            ]
        );
    }

    /**
     * Update job after execution
     *
     * @throws DatabaseException
     */
    public function updateAfterRun(int $id, string $status): void
    {
        $job = $this->findById($id);
        if (!$job) {
            return;
        }

        // Calculate next run
        $nextRunAt = $this->calculateNextRun(
            $job->scheduleType,
            $job->scheduleTime,
            $job->scheduleDayOfWeek,
            $job->scheduleDayOfMonth,
            $job->cronExpression
        );

        $this->connection->executeUpdate(
            'UPDATE backup_jobs SET
                last_run_at = NOW(),
                next_run_at = ?,
                last_status = ?,
                updated_at = NOW()
             WHERE id = ?',
            [$nextRunAt, $status, $id]
        );
    }

    /**
     * Delete backup job
     *
     * @throws DatabaseException
     */
    public function delete(int $id): void
    {
        $this->connection->executeUpdate(
            'DELETE FROM backup_jobs WHERE id = ?',
            [$id]
        );
    }

    /**
     * Calculate next run time based on schedule
     */
    private function calculateNextRun(
        string $scheduleType,
        ?string $scheduleTime,
        ?int $scheduleDayOfWeek,
        ?int $scheduleDayOfMonth,
        ?string $cronExpression
    ): ?string {
        if ($scheduleType === 'manual') {
            return null;
        }

        $now = new DateTimeImmutable();
        $time = $scheduleTime ?? '00:00:00';

        try {
            $nextRun = match ($scheduleType) {
                'daily' => $this->calculateDailyNextRun($now, $time),
                'weekly' => $this->calculateWeeklyNextRun($now, $time, $scheduleDayOfWeek ?? 1),
                'monthly' => $this->calculateMonthlyNextRun($now, $time, $scheduleDayOfMonth ?? 1),
                'custom' => $this->calculateCronNextRun($now, $cronExpression),
                default => null,
            };

            return $nextRun?->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            error_log('Failed to calculate next run: ' . $e->getMessage());
            return null;
        }
    }

    private function calculateDailyNextRun(DateTimeImmutable $now, string $time): DateTimeImmutable
    {
        [$hour, $minute] = explode(':', $time);
        $nextRun = $now->setTime((int)$hour, (int)$minute, 0);

        // If time has passed today, schedule for tomorrow
        if ($nextRun <= $now) {
            $nextRun = $nextRun->modify('+1 day');
        }

        return $nextRun;
    }

    private function calculateWeeklyNextRun(DateTimeImmutable $now, string $time, int $dayOfWeek): DateTimeImmutable
    {
        [$hour, $minute] = explode(':', $time);
        $currentDayOfWeek = (int)$now->format('N'); // 1=Monday, 7=Sunday

        // Calculate days until next occurrence
        $daysUntil = ($dayOfWeek - $currentDayOfWeek + 7) % 7;

        $nextRun = $now->modify("+$daysUntil days")->setTime((int)$hour, (int)$minute, 0);

        // If it's the same day but time has passed, schedule for next week
        if ($daysUntil === 0 && $nextRun <= $now) {
            $nextRun = $nextRun->modify('+7 days');
        }

        return $nextRun;
    }

    private function calculateMonthlyNextRun(DateTimeImmutable $now, string $time, int $dayOfMonth): DateTimeImmutable
    {
        [$hour, $minute] = explode(':', $time);
        $currentDay = (int)$now->format('d');
        $currentMonth = (int)$now->format('m');
        $currentYear = (int)$now->format('Y');

        // Try to set the day in current month
        try {
            $nextRun = $now->setDate($currentYear, $currentMonth, $dayOfMonth)->setTime((int)$hour, (int)$minute, 0);

            // If date has passed this month, go to next month
            if ($nextRun <= $now) {
                $nextRun = $nextRun->modify('+1 month');
                // Handle months with fewer days
                $nextRun = $nextRun->setDate(
                    (int)$nextRun->format('Y'),
                    (int)$nextRun->format('m'),
                    min($dayOfMonth, (int)$nextRun->format('t'))
                );
            }
        } catch (\Exception $e) {
            // Day doesn't exist in month (e.g., 31 in February), use last day
            $nextRun = $now->modify('first day of next month')->modify('last day of this month')->setTime((int)$hour, (int)$minute, 0);
        }

        return $nextRun;
    }

    private function calculateCronNextRun(DateTimeImmutable $now, ?string $cronExpression): ?DateTimeImmutable
    {
        // Basic cron parsing - for production, use a library like dragonmantank/cron-expression
        // For now, return null and implement later
        return null;
    }
}
