<?php

declare(strict_types=1);

namespace PhpBorg\Api\Controller;

use PhpBorg\Application;
use PhpBorg\Repository\BackupScheduleRepository;
use PhpBorg\Repository\BackupJobRepository;
use PhpBorg\Exception\PhpBorgException;
use DateTimeImmutable;

/**
 * Backup schedule management API controller
 */
class BackupScheduleController extends BaseController
{
    private readonly BackupScheduleRepository $scheduleRepository;
    private readonly BackupJobRepository $jobRepository;

    public function __construct(Application $app)
    {
        $this->scheduleRepository = new BackupScheduleRepository($app->getConnection());
        $this->jobRepository = new BackupJobRepository($app->getConnection());
    }

    /**
     * Get all backup schedules
     * GET /api/backup-schedules
     */
    public function index(): array
    {
        $schedules = $this->scheduleRepository->findAll();
        
        return [
            'success' => true,
            'data' => array_map(fn($schedule) => $schedule->toArray(), $schedules),
            'total' => count($schedules)
        ];
    }

    /**
     * Get backup schedule by ID
     * GET /api/backup-schedules/{id}
     */
    public function show(int $id): array
    {
        $schedule = $this->scheduleRepository->findById($id);
        
        if (!$schedule) {
            throw new PhpBorgException('Backup schedule not found', 404);
        }

        // Get job details
        $job = $this->jobRepository->findById($schedule->jobId);

        return [
            'success' => true,
            'data' => array_merge(
                $schedule->toArray(),
                ['job' => $job ? $job->toArray() : null]
            )
        ];
    }

    /**
     * Get backup schedule by job ID
     * GET /api/backup-schedules/by-job/{jobId}
     */
    public function byJob(int $jobId): array
    {
        $schedule = $this->scheduleRepository->findByJobId($jobId);
        
        return [
            'success' => true,
            'data' => $schedule ? $schedule->toArray() : null
        ];
    }

    /**
     * Create a new backup schedule
     * POST /api/backup-schedules
     */
    public function create(array $data): array
    {
        // Validate required fields
        if (!isset($data['job_id']) || empty($data['job_id'])) {
            throw new PhpBorgException('Job ID is required', 400);
        }

        if (!isset($data['type']) || empty($data['type'])) {
            throw new PhpBorgException('Schedule type is required', 400);
        }

        // Check if job exists
        if (!$this->jobRepository->exists((int)$data['job_id'])) {
            throw new PhpBorgException('Invalid job ID', 400);
        }

        // Check if job already has a schedule
        $existingSchedule = $this->scheduleRepository->findByJobId((int)$data['job_id']);
        if ($existingSchedule) {
            throw new PhpBorgException('Job already has a schedule. Please update or delete the existing schedule.', 409);
        }

        // Process weekdays if provided as array
        if (isset($data['weekdays_array']) && is_array($data['weekdays_array'])) {
            $data['weekdays'] = $this->scheduleRepository->calculateWeekdaysBitmap($data['weekdays_array']);
            unset($data['weekdays_array']);
        }

        // Process monthdays if provided as array
        if (isset($data['monthdays_array']) && is_array($data['monthdays_array'])) {
            $data['monthdays'] = $this->scheduleRepository->calculateMonthdaysBitmap($data['monthdays_array']);
            unset($data['monthdays_array']);
        }

        // Validate type-specific fields
        $this->validateScheduleData($data);

        // Create schedule
        $schedule = $this->scheduleRepository->create($data);

        return [
            'success' => true,
            'message' => 'Backup schedule created successfully',
            'data' => $schedule->toArray()
        ];
    }

    /**
     * Update a backup schedule
     * PUT /api/backup-schedules/{id}
     */
    public function update(int $id, array $data): array
    {
        // Check if schedule exists
        $schedule = $this->scheduleRepository->findById($id);
        if (!$schedule) {
            throw new PhpBorgException('Backup schedule not found', 404);
        }

        // Process weekdays if provided as array
        if (isset($data['weekdays_array']) && is_array($data['weekdays_array'])) {
            $data['weekdays'] = $this->scheduleRepository->calculateWeekdaysBitmap($data['weekdays_array']);
            unset($data['weekdays_array']);
        }

        // Process monthdays if provided as array
        if (isset($data['monthdays_array']) && is_array($data['monthdays_array'])) {
            $data['monthdays'] = $this->scheduleRepository->calculateMonthdaysBitmap($data['monthdays_array']);
            unset($data['monthdays_array']);
        }

        // Validate type-specific fields if type is changed
        if (isset($data['type'])) {
            $this->validateScheduleData($data);
        }

        // Update schedule
        $updatedSchedule = $this->scheduleRepository->update($id, $data);

        return [
            'success' => true,
            'message' => 'Backup schedule updated successfully',
            'data' => $updatedSchedule->toArray()
        ];
    }

    /**
     * Delete a backup schedule
     * DELETE /api/backup-schedules/{id}
     */
    public function delete(int $id): array
    {
        $schedule = $this->scheduleRepository->findById($id);
        if (!$schedule) {
            throw new PhpBorgException('Backup schedule not found', 404);
        }

        $deleted = $this->scheduleRepository->delete($id);

        return [
            'success' => $deleted,
            'message' => $deleted ? 'Backup schedule deleted successfully' : 'Failed to delete backup schedule'
        ];
    }

    /**
     * Get schedules that should run now
     * GET /api/backup-schedules/due
     */
    public function due(): array
    {
        $now = new DateTimeImmutable();
        $schedules = $this->scheduleRepository->findSchedulesToRun($now);
        
        return [
            'success' => true,
            'data' => array_map(fn($schedule) => $schedule->toArray(), $schedules),
            'total' => count($schedules),
            'checked_at' => $now->format('Y-m-d H:i:s')
        ];
    }

    /**
     * Preview next runs for a schedule
     * POST /api/backup-schedules/preview
     */
    public function preview(array $data): array
    {
        // Create temporary schedule object for preview
        $tempSchedule = new \PhpBorg\Entity\BackupSchedule(
            id: 0,
            jobId: 0,
            type: $data['type'] ?? 'manual',
            time: $data['time'] ?? '00:00:00',
            timezone: $data['timezone'] ?? 'UTC',
            weekdays: isset($data['weekdays_array']) 
                ? $this->scheduleRepository->calculateWeekdaysBitmap($data['weekdays_array'])
                : ($data['weekdays'] ?? null),
            monthdays: isset($data['monthdays_array'])
                ? $this->scheduleRepository->calculateMonthdaysBitmap($data['monthdays_array'])
                : ($data['monthdays'] ?? null),
            intervalHours: $data['interval_hours'] ?? null,
            cronExpression: $data['cron_expression'] ?? null,
            windowStart: $data['window_start'] ?? null,
            windowEnd: $data['window_end'] ?? null,
            maxRuntime: $data['max_runtime'] ?? 14400,
            blackoutPeriods: $data['blackout_periods'] ?? null,
            retryOnFailure: $data['retry_on_failure'] ?? true,
            maxRetries: $data['max_retries'] ?? 3,
            retryDelayMinutes: $data['retry_delay_minutes'] ?? 30,
            createdAt: new DateTimeImmutable()
        );

        // Calculate next 10 runs
        $runs = [];
        $current = new DateTimeImmutable();
        
        for ($i = 0; $i < 10; $i++) {
            $nextRun = $tempSchedule->calculateNextRun($current);
            if ($nextRun === null) {
                break;
            }
            
            $runs[] = [
                'date' => $nextRun->format('Y-m-d'),
                'time' => $nextRun->format('H:i:s'),
                'datetime' => $nextRun->format('Y-m-d H:i:s'),
                'day_name' => $nextRun->format('l'),
                'in_window' => $tempSchedule->isWithinWindow($nextRun),
                'in_blackout' => $tempSchedule->isInBlackoutPeriod($nextRun)
            ];
            
            $current = $nextRun->modify('+1 second');
        }

        return [
            'success' => true,
            'data' => [
                'description' => $tempSchedule->getDescription(),
                'next_runs' => $runs,
                'total_runs' => count($runs)
            ]
        ];
    }

    /**
     * Get available schedule types
     * GET /api/backup-schedules/types
     */
    public function types(): array
    {
        return [
            'success' => true,
            'data' => [
                [
                    'value' => 'manual',
                    'label' => 'Manual Only',
                    'description' => 'Run backup manually',
                    'icon' => 'fas fa-hand-pointer',
                    'fields' => []
                ],
                [
                    'value' => 'interval',
                    'label' => 'Interval',
                    'description' => 'Run every N hours',
                    'icon' => 'fas fa-clock',
                    'fields' => ['interval_hours']
                ],
                [
                    'value' => 'daily',
                    'label' => 'Daily',
                    'description' => 'Run every day at specific time',
                    'icon' => 'fas fa-calendar-day',
                    'fields' => ['time']
                ],
                [
                    'value' => 'weekly',
                    'label' => 'Weekly',
                    'description' => 'Run on selected weekdays',
                    'icon' => 'fas fa-calendar-week',
                    'fields' => ['time', 'weekdays']
                ],
                [
                    'value' => 'monthly',
                    'label' => 'Monthly',
                    'description' => 'Run on selected days of month',
                    'icon' => 'fas fa-calendar-alt',
                    'fields' => ['time', 'monthdays']
                ],
                [
                    'value' => 'cron',
                    'label' => 'Cron Expression',
                    'description' => 'Advanced scheduling with cron',
                    'icon' => 'fas fa-terminal',
                    'fields' => ['cron_expression']
                ]
            ]
        ];
    }

    /**
     * Validate schedule data based on type
     */
    private function validateScheduleData(array $data): void
    {
        $type = $data['type'] ?? '';
        
        switch ($type) {
            case 'interval':
                if (!isset($data['interval_hours']) || $data['interval_hours'] <= 0) {
                    throw new PhpBorgException('Interval hours must be greater than 0', 400);
                }
                break;
                
            case 'daily':
                if (!isset($data['time'])) {
                    throw new PhpBorgException('Time is required for daily schedule', 400);
                }
                break;
                
            case 'weekly':
                if (!isset($data['time'])) {
                    throw new PhpBorgException('Time is required for weekly schedule', 400);
                }
                if (!isset($data['weekdays']) || $data['weekdays'] === 0) {
                    throw new PhpBorgException('At least one weekday must be selected', 400);
                }
                break;
                
            case 'monthly':
                if (!isset($data['time'])) {
                    throw new PhpBorgException('Time is required for monthly schedule', 400);
                }
                if (!isset($data['monthdays']) || $data['monthdays'] === 0) {
                    throw new PhpBorgException('At least one day of month must be selected', 400);
                }
                break;
                
            case 'cron':
                if (!isset($data['cron_expression']) || empty($data['cron_expression'])) {
                    throw new PhpBorgException('Cron expression is required', 400);
                }
                break;
                
            case 'manual':
                // No validation needed for manual
                break;
                
            default:
                throw new PhpBorgException("Invalid schedule type: $type", 400);
        }

        // Validate time format if provided
        if (isset($data['time']) && !preg_match('/^([0-1][0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/', $data['time'])) {
            throw new PhpBorgException('Invalid time format. Use HH:MM or HH:MM:SS', 400);
        }

        // Validate window times if provided
        if (isset($data['window_start']) && !preg_match('/^([0-1][0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/', $data['window_start'])) {
            throw new PhpBorgException('Invalid window start time format', 400);
        }

        if (isset($data['window_end']) && !preg_match('/^([0-1][0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/', $data['window_end'])) {
            throw new PhpBorgException('Invalid window end time format', 400);
        }
    }
}