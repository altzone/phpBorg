<?php

declare(strict_types=1);

namespace PhpBorg\Api\Controller;

use PhpBorg\Application;
use PhpBorg\Exception\PhpBorgException;
use PhpBorg\Repository\JobRepository;

/**
 * Worker management API controller
 * Manages systemd services for scheduler and worker pool
 */
class WorkerController extends BaseController
{
    private readonly JobRepository $jobRepository;

    public function __construct(Application $app)
    {
        $this->jobRepository = $app->getJobRepository();
    }

    /**
     * GET /api/workers
     * Get status of all workers
     */
    public function list(): void
    {
        try {
            $currentUser = $_SERVER['USER'] ?? null;
            if (!$currentUser || !in_array('ROLE_ADMIN', $currentUser->roles)) {
                $this->error('Admin role required', 403, 'FORBIDDEN');
                return;
            }

            $workers = [];

            // Get scheduler status
            $workers[] = $this->getServiceStatus('phpborg-scheduler');

            // Get worker pool status (1 to 4)
            for ($i = 1; $i <= 4; $i++) {
                $workers[] = $this->getServiceStatus("phpborg-worker@{$i}");
            }

            $this->success([
                'workers' => $workers,
                'total' => count($workers),
            ]);
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'WORKER_LIST_ERROR');
        }
    }

    /**
     * GET /api/workers/:name
     * Get detailed status of a specific worker
     */
    public function show(): void
    {
        try {
            $currentUser = $_SERVER['USER'] ?? null;
            if (!$currentUser || !in_array('ROLE_ADMIN', $currentUser->roles)) {
                $this->error('Admin role required', 403, 'FORBIDDEN');
                return;
            }

            $workerName = $_SERVER['ROUTE_PARAMS']['name'] ?? '';

            if (!$this->isValidWorkerName($workerName)) {
                $this->error('Invalid worker name', 400, 'INVALID_WORKER_NAME');
                return;
            }

            $status = $this->getServiceStatus($workerName);

            $this->success(['worker' => $status]);
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'WORKER_SHOW_ERROR');
        }
    }

    /**
     * POST /api/workers/:name/start
     * Start a worker service
     */
    public function start(): void
    {
        try {
            $currentUser = $_SERVER['USER'] ?? null;
            if (!$currentUser || !in_array('ROLE_ADMIN', $currentUser->roles)) {
                $this->error('Admin role required', 403, 'FORBIDDEN');
                return;
            }

            $workerName = $_SERVER['ROUTE_PARAMS']['name'] ?? '';

            if (!$this->isValidWorkerName($workerName)) {
                $this->error('Invalid worker name', 400, 'INVALID_WORKER_NAME');
                return;
            }

            $this->executeSystemctl('start', $workerName);

            $this->success([
                'worker' => $workerName,
                'action' => 'start',
                'message' => 'Worker started successfully',
            ]);
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'WORKER_START_ERROR');
        }
    }

    /**
     * POST /api/workers/:name/stop
     * Stop a worker service
     */
    public function stop(): void
    {
        try {
            $currentUser = $_SERVER['USER'] ?? null;
            if (!$currentUser || !in_array('ROLE_ADMIN', $currentUser->roles)) {
                $this->error('Admin role required', 403, 'FORBIDDEN');
                return;
            }

            $workerName = $_SERVER['ROUTE_PARAMS']['name'] ?? '';

            if (!$this->isValidWorkerName($workerName)) {
                $this->error('Invalid worker name', 400, 'INVALID_WORKER_NAME');
                return;
            }

            $this->executeSystemctl('stop', $workerName);

            $this->success([
                'worker' => $workerName,
                'action' => 'stop',
                'message' => 'Worker stopped successfully',
            ]);
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'WORKER_STOP_ERROR');
        }
    }

    /**
     * POST /api/workers/:name/restart
     * Restart a worker service
     */
    public function restart(): void
    {
        try {
            $currentUser = $_SERVER['USER'] ?? null;
            if (!$currentUser || !in_array('ROLE_ADMIN', $currentUser->roles)) {
                $this->error('Admin role required', 403, 'FORBIDDEN');
                return;
            }

            $workerName = $_SERVER['ROUTE_PARAMS']['name'] ?? '';

            if (!$this->isValidWorkerName($workerName)) {
                $this->error('Invalid worker name', 400, 'INVALID_WORKER_NAME');
                return;
            }

            $this->executeSystemctl('restart', $workerName);

            $this->success([
                'worker' => $workerName,
                'action' => 'restart',
                'message' => 'Worker restarted successfully',
            ]);
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'WORKER_RESTART_ERROR');
        }
    }

    /**
     * GET /api/workers/:name/logs
     * Get logs for a worker
     */
    public function logs(): void
    {
        try {
            $currentUser = $_SERVER['USER'] ?? null;
            if (!$currentUser || !in_array('ROLE_ADMIN', $currentUser->roles)) {
                $this->error('Admin role required', 403, 'FORBIDDEN');
                return;
            }

            $workerName = $_SERVER['ROUTE_PARAMS']['name'] ?? '';
            $lines = isset($_GET['lines']) ? (int)$_GET['lines'] : 100;
            $since = $_GET['since'] ?? '1 hour ago';

            if (!$this->isValidWorkerName($workerName)) {
                $this->error('Invalid worker name', 400, 'INVALID_WORKER_NAME');
                return;
            }

            // Sanitize inputs
            $lines = max(1, min(1000, $lines)); // Between 1 and 1000
            $since = escapeshellarg($since);

            // Get logs using journalctl
            $command = sprintf(
                'sudo journalctl -u %s --no-pager --since %s -n %d 2>&1',
                escapeshellarg($workerName),
                $since,
                $lines
            );

            exec($command, $output, $returnCode);

            $logs = implode("\n", $output);

            $this->success([
                'worker' => $workerName,
                'logs' => $logs,
                'lines' => count($output),
            ]);
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'WORKER_LOGS_ERROR');
        }
    }

    /**
     * Get service status using systemctl
     */
    private function getServiceStatus(string $serviceName): array
    {
        // Get is-active status
        $activeCommand = sprintf('sudo systemctl is-active %s 2>&1', escapeshellarg($serviceName));
        exec($activeCommand, $activeOutput, $activeCode);
        $isActive = trim($activeOutput[0] ?? 'unknown') === 'active';

        // Get detailed status
        $statusCommand = sprintf('sudo systemctl status %s --no-pager -l 2>&1', escapeshellarg($serviceName));
        exec($statusCommand, $statusOutput, $statusCode);

        // Parse memory and CPU from status output
        $memory = null;
        $cpu = null;
        $pid = null;
        $uptime = null;

        foreach ($statusOutput as $line) {
            // Parse Memory (handles both "10.4M" and "10.4M (max: 512.0M...)")
            if (preg_match('/Memory:\s*([\d.]+[KMGT]?B?)/', $line, $matches)) {
                $memory = $matches[1];
            }
            // Parse CPU (handles "104ms" format)
            if (preg_match('/CPU:\s*([\d.]+[a-z]+)/', $line, $matches)) {
                $cpu = $matches[1];
            }
            // Parse Main PID
            if (preg_match('/Main PID:\s*(\d+)/', $line, $matches)) {
                $pid = (int)$matches[1];
            }
            // Parse Active/Uptime
            if (preg_match('/Active:\s*active\s*\(running\)\s*since\s+(.+);\s*(.+)$/', $line, $matches)) {
                $uptime = trim($matches[2]);
            }
        }

        // Get current running job for worker pool instances
        $currentJob = null;
        if (strpos($serviceName, 'phpborg-worker@') === 0) {
            try {
                // Extract worker number from service name (e.g., phpborg-worker@2 -> WORKER #2)
                preg_match('/phpborg-worker@(\d+)/', $serviceName, $matches);
                if (isset($matches[1])) {
                    $workerTag = "WORKER #{$matches[1]}";

                    // Find job with matching worker_id
                    $runningJobs = $this->jobRepository->findByStatus('running');
                    foreach ($runningJobs as $job) {
                        if ($job->workerId === $workerTag) {
                            $currentJob = [
                                'id' => $job->id,
                                'type' => $job->type,
                                'description' => $job->payload['description'] ?? $job->type,
                                'progress' => $job->progress,
                            ];
                            break;
                        }
                    }
                }
            } catch (\Exception $e) {
                // Silently fail - current_job will be null
            }
        }

        return [
            'name' => $serviceName,
            'display_name' => $this->getDisplayName($serviceName),
            'active' => $isActive,
            'status' => trim($activeOutput[0] ?? 'unknown'),
            'memory' => $memory,
            'cpu' => $cpu,
            'pid' => $pid,
            'uptime' => $uptime,
            'current_job' => $currentJob,
        ];
    }

    /**
     * Execute systemctl command
     */
    private function executeSystemctl(string $action, string $serviceName): void
    {
        $command = sprintf(
            'sudo systemctl %s %s 2>&1',
            escapeshellarg($action),
            escapeshellarg($serviceName)
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new PhpBorgException(
                sprintf('Failed to %s %s: %s', $action, $serviceName, implode("\n", $output))
            );
        }
    }

    /**
     * Validate worker name
     */
    private function isValidWorkerName(string $name): bool
    {
        $validNames = [
            'phpborg-scheduler',
            'phpborg-worker@1',
            'phpborg-worker@2',
            'phpborg-worker@3',
            'phpborg-worker@4',
        ];

        return in_array($name, $validNames);
    }

    /**
     * Get display name for service
     */
    private function getDisplayName(string $serviceName): string
    {
        return match ($serviceName) {
            'phpborg-scheduler' => 'Scheduler',
            'phpborg-worker@1' => 'Worker #1',
            'phpborg-worker@2' => 'Worker #2',
            'phpborg-worker@3' => 'Worker #3',
            'phpborg-worker@4' => 'Worker #4',
            default => $serviceName,
        };
    }
}
