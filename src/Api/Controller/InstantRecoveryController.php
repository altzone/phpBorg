<?php

declare(strict_types=1);

namespace PhpBorg\Api\Controller;

use PhpBorg\Application;
use PhpBorg\Exception\PhpBorgException;

/**
 * Instant Recovery API Controller
 * Manages ephemeral database instances mounted from backups
 */
final class InstantRecoveryController extends BaseController
{
    private $sessionRepo;
    private $archiveRepo;
    private $serverRepo;
    private $jobQueue;

    public function __construct(Application $app)
    {
        $this->sessionRepo = $app->getInstantRecoverySessionRepository();
        $this->archiveRepo = $app->getArchiveRepository();
        $this->serverRepo = $app->getServerRepository();
        $this->jobQueue = $app->getJobQueue();
    }

    /**
     * List all instant recovery sessions
     * GET /api/instant-recovery
     */
    public function list(): void
    {
        try {
            $sessions = $this->sessionRepo->findAll();

            $this->success([
                'sessions' => array_map(fn($s) => $s->toArray(), $sessions)
            ]);

        } catch (\Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    /**
     * List active instant recovery sessions
     * GET /api/instant-recovery/active
     */
    public function active(): void
    {
        try {
            $sessions = $this->sessionRepo->findActive();

            $this->success([
                'sessions' => array_map(fn($s) => $s->toArray(), $sessions)
            ]);

        } catch (\Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Get instant recovery session details
     * GET /api/instant-recovery/:id
     */
    public function show(int $id): void
    {
        try {
            $session = $this->sessionRepo->findById($id);

            if (!$session) {
                $this->error("Instant recovery session not found", 404);
                return;
            }

            $this->success($session->toArray());

        } catch (\Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Start instant recovery session
     * POST /api/instant-recovery/start
     *
     * Body: {
     *   "archive_id": 123,
     *   "deployment_location": "remote|local"  // Optional, defaults to "remote"
     * }
     */
    public function start(): void
    {
        try {
            $data = $this->getJsonBody();

            // Validate input
            if (empty($data['archive_id'])) {
                $this->error("Missing required field: archive_id", 400);
                return;
            }

            $archiveId = (int)$data['archive_id'];
            $deploymentLocation = $data['deployment_location'] ?? 'remote';

            // Validate deployment location
            if (!in_array($deploymentLocation, ['remote', 'local'])) {
                $this->error("Invalid deployment_location. Must be 'remote' or 'local'.", 400);
                return;
            }

            // Get archive
            $archive = $this->archiveRepo->findById($archiveId);
            if (!$archive) {
                $this->error("Archive not found", 404);
                return;
            }

            // Get server
            $server = $this->serverRepo->findById($archive->serverId);
            if (!$server) {
                $this->error("Server not found", 404);
                return;
            }

            // Determine database type from repository type
            $dbType = $archive->type ?? 'postgresql';

            // Check if already running
            $existing = $this->sessionRepo->findByArchiveId($archiveId);
            if ($existing && $existing->isActive()) {
                $this->success([
                    'message' => 'Instant recovery session already active',
                    'session' => $existing->toArray()
                ]);
                return;
            }

            // Create job to start instant recovery (executed by worker as phpborg user)
            $jobId = $this->jobQueue->push('instant_recovery_start', [
                'archive_id' => $archiveId,
                'deployment_location' => $deploymentLocation,
                'db_type' => $dbType,
            ]);

            $this->success([
                'job_id' => $jobId,
                'archive_id' => $archiveId,
                'deployment_location' => $deploymentLocation
            ], 'Instant recovery job created', 202);

        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), $e->getCode() ?: 500);
        } catch (\Exception $e) {
            $this->error("Failed to start instant recovery: " . $e->getMessage(), 500);
        }
    }

    /**
     * Stop instant recovery session
     * POST /api/instant-recovery/stop/:id
     */
    public function stop(int $id): void
    {
        try {
            $session = $this->sessionRepo->findById($id);

            if (!$session) {
                $this->error("Instant recovery session not found", 404);
                return;
            }

            if (!$session->isActive()) {
                $this->error("Session is not active", 400);
                return;
            }

            // Create job to stop instant recovery (executed by worker as phpborg user)
            $jobId = $this->jobQueue->push('instant_recovery_stop', [
                'session_id' => $id,
            ]);

            $this->success([
                'job_id' => $jobId,
                'session_id' => $id
            ], 'Instant recovery stop job created', 202);

        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), $e->getCode() ?: 500);
        } catch (\Exception $e) {
            $this->error("Failed to stop instant recovery: " . $e->getMessage(), 500);
        }
    }

    /**
     * Delete instant recovery session record
     * DELETE /api/instant-recovery/:id
     */
    public function delete(int $id): void
    {
        try {
            $session = $this->sessionRepo->findById($id);

            if (!$session) {
                $this->error("Instant recovery session not found", 404);
                return;
            }

            // If active, stop it first via job
            if ($session->isActive()) {
                $jobId = $this->jobQueue->push('instant_recovery_stop', [
                    'session_id' => $id,
                ]);

                $this->success([
                    'job_id' => $jobId,
                    'session_id' => $id
                ], 'Active session will be stopped, then deleted', 202);
                return;
            }

            // Delete record (only if inactive)
            $this->sessionRepo->delete($id);

            $this->success([
                'message' => 'Instant recovery session deleted successfully'
            ]);

        } catch (\Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }
}
