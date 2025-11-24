<?php

declare(strict_types=1);

namespace PhpBorg\Api\Controller;

use PhpBorg\Application;
use PhpBorg\Service\PhpBorgUpdateService;
use PhpBorg\Service\Queue\JobQueue;

/**
 * API Controller for phpBorg self-update management
 *
 * Endpoints:
 * - GET    /api/phpborg-update/check           Check for updates
 * - GET    /api/phpborg-update/version         Get current version
 * - GET    /api/phpborg-update/changelog       Get changelog
 * - POST   /api/phpborg-update/start           Start update process
 */
final class PhpBorgUpdateController extends BaseController
{
    private readonly PhpBorgUpdateService $updateService;
    private readonly JobQueue $jobQueue;

    public function __construct(Application $app)
    {
        $this->updateService = $app->getPhpBorgUpdateService();
        $this->jobQueue = $app->getJobQueue();
    }

    /**
     * GET /api/phpborg-update/check - Check for updates
     *
     * Creates a job to check for updates (runs as phpborg user to avoid git permission issues)
     */
    public function check(): void
    {
        try {
            // Create job for update check
            $jobId = $this->jobQueue->push('phpborg_update_check', [], 'default', 1);

            $this->success([
                'job_id' => $jobId,
                'message' => 'Update check started'
            ], 'Update check job created', 202);

        } catch (\Exception $e) {
            $this->error('Failed to start update check: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/phpborg-update/version - Get current version
     */
    public function version(): void
    {
        try {
            $version = $this->updateService->getCurrentVersion();

            $this->success($version, 'Version retrieved successfully');

        } catch (\Exception $e) {
            $this->error('Failed to get version: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/phpborg-update/changelog - Get changelog
     */
    public function changelog(): void
    {
        try {
            $changelog = $this->updateService->getChangelog();

            $this->success($changelog, 'Changelog retrieved successfully');

        } catch (\Exception $e) {
            $this->error('Failed to get changelog: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/phpborg-update/start - Start update
     *
     * Body: {
     *   "target_commit": "abc123..." (optional, default: latest master)
     * }
     */
    public function start(): void
    {
        $body = $this->getJsonBody();
        $targetCommit = $body['target_commit'] ?? null;

        try {
            // Create job for update
            $jobId = $this->jobQueue->push('phpborg_update', [
                'target_commit' => $targetCommit
            ], 'default', 1); // default queue, 1 max attempt

            $this->success([
                'job_id' => $jobId,
                'message' => 'Update started'
            ], 'Update job created successfully', 202);

        } catch (\Exception $e) {
            $this->error('Failed to start update: ' . $e->getMessage(), 500);
        }
    }
}
