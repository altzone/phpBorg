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
    private $borgRepoRepo;
    private $jobQueue;

    public function __construct(Application $app)
    {
        $this->sessionRepo = $app->getInstantRecoverySessionRepository();
        $this->archiveRepo = $app->getArchiveRepository();
        $this->serverRepo = $app->getServerRepository();
        $this->borgRepoRepo = $app->getBorgRepositoryRepository();
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

            // Enrich each session with server and archive info
            $enrichedSessions = array_map(function($session) {
                $sessionData = $session->toArray();

                // Add server info
                $server = $this->serverRepo->findById($session->serverId);
                if ($server) {
                    $sessionData['server_name'] = $server->name;
                    $sessionData['server_hostname'] = $server->host;
                }

                // Add archive info
                $archive = $this->archiveRepo->findById($session->archiveId);
                if ($archive) {
                    $sessionData['archive_name'] = $archive->name;
                    $sessionData['archive_time'] = $archive->start->format('Y-m-d H:i:s');
                }

                // Calculate connection host
                $sessionData['connection_host'] = $session->deploymentLocation === 'local'
                    ? '127.0.0.1'
                    : ($server->host ?? 'unknown');

                // Add default database connection info
                if ($session->dbType === 'postgresql') {
                    $sessionData['db_user'] = 'postgres';
                    $sessionData['db_name'] = 'postgres';
                } elseif ($session->dbType === 'mysql' || $session->dbType === 'mariadb') {
                    $sessionData['db_user'] = 'root';
                    $sessionData['db_name'] = 'mysql';
                } elseif ($session->dbType === 'mongodb') {
                    $sessionData['db_user'] = 'admin';
                    $sessionData['db_name'] = 'admin';
                }

                return $sessionData;
            }, $sessions);

            $this->success([
                'sessions' => $enrichedSessions
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

            // Enrich session data with server and archive information
            $sessionData = $session->toArray();

            // Add server info
            $server = $this->serverRepo->findById($session->serverId);
            if ($server) {
                $sessionData['server_name'] = $server->name;
                $sessionData['server_hostname'] = $server->host;
            }

            // Add archive info
            $archive = $this->archiveRepo->findById($session->archiveId);
            if ($archive) {
                $sessionData['archive_name'] = $archive->name;
            }

            // Calculate connection host (127.0.0.1 for local, server IP for remote)
            $sessionData['connection_host'] = $session->deploymentLocation === 'local'
                ? '127.0.0.1'
                : ($server->host ?? 'unknown');

            // Add default database connection info (TODO: get from database_info table)
            if ($session->dbType === 'postgresql') {
                $sessionData['db_user'] = 'postgres';
                $sessionData['db_name'] = 'postgres';
            } elseif ($session->dbType === 'mysql' || $session->dbType === 'mariadb') {
                $sessionData['db_user'] = 'root';
                $sessionData['db_name'] = 'mysql';
            } elseif ($session->dbType === 'mongodb') {
                $sessionData['db_user'] = 'admin';
                $sessionData['db_name'] = 'admin';
            }

            $this->success($sessionData);

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

            // Get repository to determine database type
            $repository = $this->borgRepoRepo->findByRepoId($archive->repoId);
            if (!$repository) {
                $this->error("Repository not found for archive", 404);
                return;
            }

            // Determine database type from repository type
            $dbType = $repository->type ?? 'postgresql';

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
