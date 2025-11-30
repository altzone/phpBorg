<?php

declare(strict_types=1);

namespace PhpBorg\Api\Controller;

use PhpBorg\Application;
use PhpBorg\Exception\PhpBorgException;
use PhpBorg\Service\Queue\JobQueue;

/**
 * Job management API controller
 */
class JobController extends BaseController
{
    private readonly JobQueue $jobQueue;

    public function __construct(Application $app)
    {
        $this->jobQueue = $app->getJobQueue();
    }

    /**
     * GET /api/jobs
     * List all jobs
     */
    public function list(): void
    {
        try {
            $limit = (int) ($_GET['limit'] ?? 50);
            $jobs = $this->jobQueue->getRecentJobs($limit);

            $this->success([
                'jobs' => array_map(fn($job) => $job->toArray(), $jobs),
                'total' => count($jobs),
            ]);
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'JOB_LIST_ERROR');
        }
    }

    /**
     * GET /api/jobs/:id
     * Get job details
     */
    public function show(): void
    {
        try {
            $jobId = (int) ($_SERVER['ROUTE_PARAMS']['id'] ?? 0);

            if ($jobId <= 0) {
                $this->error('Invalid job ID', 400, 'INVALID_JOB_ID');
                return;
            }

            $job = $this->jobQueue->getJob($jobId);

            if (!$job) {
                $this->error('Job not found', 404, 'JOB_NOT_FOUND');
                return;
            }

            // Include real-time progress info from Redis for running jobs
            $progressInfo = null;
            $progressSteps = [];
            if ($job->status === 'running' || $job->status === 'completed') {
                $progressInfo = $this->jobQueue->getProgressInfo($jobId);
                $progressSteps = $this->jobQueue->getProgressSteps($jobId);
            }

            $this->success([
                'job' => $job->toArray(),
                'progress_info' => $progressInfo,
                'progress_steps' => $progressSteps
            ]);
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'JOB_DETAIL_ERROR');
        }
    }

    /**
     * POST /api/jobs/:id/cancel
     * Cancel a pending/running job
     */
    public function cancel(): void
    {
        try {
            $jobId = (int) ($_SERVER['ROUTE_PARAMS']['id'] ?? 0);

            if ($jobId <= 0) {
                $this->error('Invalid job ID', 400, 'INVALID_JOB_ID');
                return;
            }

            $success = $this->jobQueue->cancel($jobId);

            if (!$success) {
                $this->error('Cannot cancel job (not found or already finished)', 400, 'CANCEL_FAILED');
                return;
            }

            $this->success(['cancelled' => true], 'Job cancelled successfully');
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'JOB_CANCEL_ERROR');
        }
    }

    /**
     * POST /api/jobs/:id/retry
     * Retry a failed job
     */
    public function retry(): void
    {
        try {
            $jobId = (int) ($_SERVER['ROUTE_PARAMS']['id'] ?? 0);

            if ($jobId <= 0) {
                $this->error('Invalid job ID', 400, 'INVALID_JOB_ID');
                return;
            }

            $success = $this->jobQueue->retry($jobId);

            if (!$success) {
                $this->error('Cannot retry job (not found, not failed, or max attempts reached)', 400, 'RETRY_FAILED');
                return;
            }

            $this->success(['retried' => true], 'Job queued for retry');
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'JOB_RETRY_ERROR');
        }
    }

    /**
     * GET /api/jobs/:id/progress
     * Get real-time progress info from Redis
     */
    public function progress(): void
    {
        try {
            $jobId = (int) ($_SERVER['ROUTE_PARAMS']['id'] ?? 0);

            if ($jobId <= 0) {
                $this->error('Invalid job ID', 400, 'INVALID_JOB_ID');
                return;
            }

            $progressInfo = $this->jobQueue->getProgressInfo($jobId);

            if ($progressInfo === null) {
                $this->error('No progress info available', 404, 'PROGRESS_NOT_FOUND');
                return;
            }

            $this->success(['progress' => $progressInfo]);
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'PROGRESS_ERROR');
        }
    }

    /**
     * GET /api/jobs/stats
     * Get queue statistics
     */
    public function stats(): void
    {
        try {
            $allStats = $this->jobQueue->getStats();
            // Frontend expects flat stats object with total, pending, running, etc.
            $this->success(['stats' => $allStats['database']]);
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'STATS_ERROR');
        }
    }

    /**
     * POST /api/jobs/test
     * Create a test job for demonstration
     */
    public function createTest(): void
    {
        try {
            $jobId = $this->jobQueue->push(
                'test_job',
                [
                    'message' => 'This is a test job',
                    'timestamp' => time(),
                ],
                'default',
                3,
                1 // Admin user ID
            );

            $this->success([
                'job_id' => $jobId,
                'message' => 'Test job created successfully'
            ]);
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'TEST_JOB_ERROR');
        }
    }
}
