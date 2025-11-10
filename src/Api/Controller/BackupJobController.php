<?php

declare(strict_types=1);

namespace PhpBorg\Api\Controller;

use PhpBorg\Application;
use PhpBorg\Repository\BackupJobRepository;
use PhpBorg\Repository\BorgRepositoryRepository;
use PhpBorg\Exception\PhpBorgException;

/**
 * Backup job management API controller
 */
class BackupJobController extends BaseController
{
    private readonly BackupJobRepository $backupJobRepository;
    private readonly BorgRepositoryRepository $repositoryRepository;

    public function __construct(Application $app)
    {
        $this->backupJobRepository = new BackupJobRepository($app->getConnection());
        $this->repositoryRepository = new BorgRepositoryRepository($app->getConnection());
    }

    /**
     * GET /api/backup-jobs
     * List all backup jobs
     */
    public function list(): void
    {
        try {
            $currentUser = $_SERVER['USER'] ?? null;
            if (!$currentUser) {
                $this->error('Authentication required', 401, 'UNAUTHORIZED');
                return;
            }

            $jobs = $this->backupJobRepository->findAll();

            // Enrich with repository info
            $jobsArray = array_map(function ($job) {
                $jobArray = $job->toArray();
                $repository = $this->repositoryRepository->findById($job->repositoryId);
                $jobArray['repository'] = $repository ? $repository->toArray() : null;
                return $jobArray;
            }, $jobs);

            $this->success([
                'backup_jobs' => $jobsArray,
                'total' => count($jobs),
            ]);
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'BACKUP_JOB_LIST_ERROR');
        }
    }

    /**
     * GET /api/backup-jobs/:id
     * Get backup job details
     */
    public function show(): void
    {
        try {
            $currentUser = $_SERVER['USER'] ?? null;
            if (!$currentUser) {
                $this->error('Authentication required', 401, 'UNAUTHORIZED');
                return;
            }

            $jobId = (int)($_SERVER['ROUTE_PARAMS']['id'] ?? 0);

            if ($jobId <= 0) {
                $this->error('Invalid backup job ID', 400, 'INVALID_JOB_ID');
                return;
            }

            $job = $this->backupJobRepository->findById($jobId);

            if (!$job) {
                $this->error('Backup job not found', 404, 'JOB_NOT_FOUND');
                return;
            }

            $jobArray = $job->toArray();
            $repository = $this->repositoryRepository->findById($job->repositoryId);
            $jobArray['repository'] = $repository ? $repository->toArray() : null;

            $this->success(['backup_job' => $jobArray]);
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'BACKUP_JOB_SHOW_ERROR');
        }
    }

    /**
     * GET /api/repositories/:id/backup-jobs
     * Get all jobs for a repository
     */
    public function listByRepository(): void
    {
        try {
            $currentUser = $_SERVER['USER'] ?? null;
            if (!$currentUser) {
                $this->error('Authentication required', 401, 'UNAUTHORIZED');
                return;
            }

            $repositoryId = (int)($_SERVER['ROUTE_PARAMS']['id'] ?? 0);

            if ($repositoryId <= 0) {
                $this->error('Invalid repository ID', 400, 'INVALID_REPOSITORY_ID');
                return;
            }

            $jobs = $this->backupJobRepository->findByRepositoryId($repositoryId);
            $jobsArray = array_map(fn($job) => $job->toArray(), $jobs);

            $this->success([
                'backup_jobs' => $jobsArray,
                'total' => count($jobs),
            ]);
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'BACKUP_JOB_LIST_ERROR');
        }
    }

    /**
     * POST /api/backup-jobs
     * Create new backup job
     */
    public function create(): void
    {
        try {
            // Only ROLE_ADMIN and ROLE_OPERATOR can create backup jobs
            $currentUser = $_SERVER['USER'] ?? null;
            if (!$currentUser || !in_array('ROLE_ADMIN', $currentUser->roles) && !in_array('ROLE_OPERATOR', $currentUser->roles)) {
                $this->error('Admin or Operator role required', 403, 'FORBIDDEN');
                return;
            }

            $data = $this->getJsonBody();

            // Validate required fields
            $this->validateRequired($data, ['name', 'repository_id', 'schedule_type']);

            // Validate repository exists
            $repository = $this->repositoryRepository->findById((int)$data['repository_id']);
            if (!$repository) {
                $this->error('Repository not found', 404, 'REPOSITORY_NOT_FOUND');
                return;
            }

            // Validate schedule type
            $validScheduleTypes = ['manual', 'daily', 'weekly', 'monthly', 'custom'];
            if (!in_array($data['schedule_type'], $validScheduleTypes)) {
                $this->error('Invalid schedule type', 400, 'INVALID_SCHEDULE_TYPE');
                return;
            }

            // Validate schedule parameters based on type
            if ($data['schedule_type'] === 'weekly' && empty($data['schedule_day_of_week'])) {
                $this->error('Day of week required for weekly schedule', 400, 'MISSING_DAY_OF_WEEK');
                return;
            }

            if ($data['schedule_type'] === 'monthly' && empty($data['schedule_day_of_month'])) {
                $this->error('Day of month required for monthly schedule', 400, 'MISSING_DAY_OF_MONTH');
                return;
            }

            if ($data['schedule_type'] === 'custom' && empty($data['cron_expression'])) {
                $this->error('Cron expression required for custom schedule', 400, 'MISSING_CRON_EXPRESSION');
                return;
            }

            // Create backup job
            $jobId = $this->backupJobRepository->create(
                name: $data['name'],
                repositoryId: (int)$data['repository_id'],
                scheduleType: $data['schedule_type'],
                scheduleTime: $data['schedule_time'] ?? null,
                scheduleDayOfWeek: isset($data['schedule_day_of_week']) ? (int)$data['schedule_day_of_week'] : null,
                scheduleDayOfMonth: isset($data['schedule_day_of_month']) ? (int)$data['schedule_day_of_month'] : null,
                cronExpression: $data['cron_expression'] ?? null,
                enabled: $data['enabled'] ?? true,
                notifyOnSuccess: $data['notify_on_success'] ?? false,
                notifyOnFailure: $data['notify_on_failure'] ?? true
            );

            // Get created job
            $job = $this->backupJobRepository->findById($jobId);
            $jobArray = $job->toArray();
            $jobArray['repository'] = $repository->toArray();

            $this->success(
                ['backup_job' => $jobArray],
                'Backup job created successfully',
                201
            );
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'BACKUP_JOB_CREATE_ERROR');
        }
    }

    /**
     * PUT /api/backup-jobs/:id
     * Update backup job
     */
    public function update(): void
    {
        try {
            // Only ROLE_ADMIN and ROLE_OPERATOR can update backup jobs
            $currentUser = $_SERVER['USER'] ?? null;
            if (!$currentUser || !in_array('ROLE_ADMIN', $currentUser->roles) && !in_array('ROLE_OPERATOR', $currentUser->roles)) {
                $this->error('Admin or Operator role required', 403, 'FORBIDDEN');
                return;
            }

            $jobId = (int)($_SERVER['ROUTE_PARAMS']['id'] ?? 0);

            if ($jobId <= 0) {
                $this->error('Invalid backup job ID', 400, 'INVALID_JOB_ID');
                return;
            }

            // Check job exists
            $job = $this->backupJobRepository->findById($jobId);
            if (!$job) {
                $this->error('Backup job not found', 404, 'JOB_NOT_FOUND');
                return;
            }

            $data = $this->getJsonBody();

            // Update backup job
            $this->backupJobRepository->update(
                id: $jobId,
                name: $data['name'] ?? $job->name,
                scheduleType: $data['schedule_type'] ?? $job->scheduleType,
                scheduleTime: $data['schedule_time'] ?? $job->scheduleTime,
                scheduleDayOfWeek: isset($data['schedule_day_of_week']) ? (int)$data['schedule_day_of_week'] : $job->scheduleDayOfWeek,
                scheduleDayOfMonth: isset($data['schedule_day_of_month']) ? (int)$data['schedule_day_of_month'] : $job->scheduleDayOfMonth,
                cronExpression: $data['cron_expression'] ?? $job->cronExpression,
                enabled: isset($data['enabled']) ? (bool)$data['enabled'] : $job->enabled,
                notifyOnSuccess: isset($data['notify_on_success']) ? (bool)$data['notify_on_success'] : $job->notifyOnSuccess,
                notifyOnFailure: isset($data['notify_on_failure']) ? (bool)$data['notify_on_failure'] : $job->notifyOnFailure
            );

            // Get updated job
            $job = $this->backupJobRepository->findById($jobId);
            $jobArray = $job->toArray();
            $repository = $this->repositoryRepository->findById($job->repositoryId);
            $jobArray['repository'] = $repository ? $repository->toArray() : null;

            $this->success(['backup_job' => $jobArray], 'Backup job updated successfully');
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'BACKUP_JOB_UPDATE_ERROR');
        }
    }

    /**
     * POST /api/backup-jobs/:id/toggle
     * Enable/disable backup job
     */
    public function toggle(): void
    {
        try {
            $currentUser = $_SERVER['USER'] ?? null;
            if (!$currentUser || !in_array('ROLE_ADMIN', $currentUser->roles) && !in_array('ROLE_OPERATOR', $currentUser->roles)) {
                $this->error('Admin or Operator role required', 403, 'FORBIDDEN');
                return;
            }

            $jobId = (int)($_SERVER['ROUTE_PARAMS']['id'] ?? 0);

            if ($jobId <= 0) {
                $this->error('Invalid backup job ID', 400, 'INVALID_JOB_ID');
                return;
            }

            $job = $this->backupJobRepository->findById($jobId);
            if (!$job) {
                $this->error('Backup job not found', 404, 'JOB_NOT_FOUND');
                return;
            }

            // Toggle enabled status
            $this->backupJobRepository->update(
                id: $jobId,
                name: $job->name,
                scheduleType: $job->scheduleType,
                scheduleTime: $job->scheduleTime,
                scheduleDayOfWeek: $job->scheduleDayOfWeek,
                scheduleDayOfMonth: $job->scheduleDayOfMonth,
                cronExpression: $job->cronExpression,
                enabled: !$job->enabled,
                notifyOnSuccess: $job->notifyOnSuccess,
                notifyOnFailure: $job->notifyOnFailure
            );

            $this->success(
                ['enabled' => !$job->enabled],
                $job->enabled ? 'Backup job disabled' : 'Backup job enabled'
            );
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'BACKUP_JOB_TOGGLE_ERROR');
        }
    }

    /**
     * DELETE /api/backup-jobs/:id
     * Delete backup job
     */
    public function delete(): void
    {
        try {
            // Only ROLE_ADMIN can delete backup jobs
            $currentUser = $_SERVER['USER'] ?? null;
            if (!$currentUser || !in_array('ROLE_ADMIN', $currentUser->roles)) {
                $this->error('Admin role required', 403, 'FORBIDDEN');
                return;
            }

            $jobId = (int)($_SERVER['ROUTE_PARAMS']['id'] ?? 0);

            if ($jobId <= 0) {
                $this->error('Invalid backup job ID', 400, 'INVALID_JOB_ID');
                return;
            }

            // Check job exists
            $job = $this->backupJobRepository->findById($jobId);
            if (!$job) {
                $this->error('Backup job not found', 404, 'JOB_NOT_FOUND');
                return;
            }

            // Delete job
            $this->backupJobRepository->delete($jobId);

            $this->success(['id' => $jobId], 'Backup job deleted successfully');
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'BACKUP_JOB_DELETE_ERROR');
        }
    }
}
