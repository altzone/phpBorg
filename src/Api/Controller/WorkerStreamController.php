<?php

declare(strict_types=1);

namespace PhpBorg\Api\Controller;

use PhpBorg\Application;
use PhpBorg\Repository\JobRepository;

/**
 * Server-Sent Events controller for real-time worker updates
 */
class WorkerStreamController extends BaseController
{
    private readonly JobRepository $jobRepository;

    public function __construct(Application $app)
    {
        $this->jobRepository = $app->getJobRepository();
    }

    /**
     * GET /api/workers/stream
     * Stream worker status updates using Server-Sent Events (SSE)
     */
    public function stream(): void
    {
        // Authentication is already handled by router middleware

        // Set SSE headers
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no'); // Disable nginx buffering

        // Disable output buffering
        if (ob_get_level()) {
            ob_end_clean();
        }

        // Keep track of last state to only send updates when something changes
        $lastState = null;

        // Send updates every 2 seconds
        $maxDuration = 300; // 5 minutes max connection time
        $startTime = time();

        while (time() - $startTime < $maxDuration) {
            try {
                // Get current worker status
                $workers = [];

                // Scheduler
                $workers[] = $this->getServiceStatus('phpborg-scheduler');

                // Worker pool (1 to 4)
                for ($i = 1; $i <= 4; $i++) {
                    $workers[] = $this->getServiceStatus("phpborg-worker@{$i}");
                }

                $currentState = json_encode($workers);

                // Only send if state has changed
                if ($currentState !== $lastState) {
                    $this->sendEvent('update', [
                        'workers' => $workers,
                        'timestamp' => time(),
                    ]);

                    $lastState = $currentState;
                }

                // Send heartbeat every cycle (even if no changes)
                $this->sendEvent('heartbeat', ['timestamp' => time()]);

                // Flush output
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();

                // Wait before next update
                sleep(2);

            } catch (\Exception $e) {
                $this->sendEvent('error', ['message' => $e->getMessage()]);
                break;
            }

            // Check if client disconnected
            if (connection_aborted()) {
                break;
            }
        }
    }

    /**
     * Send an SSE event
     */
    private function sendEvent(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo "data: " . json_encode($data) . "\n\n";
    }

    /**
     * Get service status using systemctl
     * (Same logic as WorkerController)
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
                // Extract worker number from service name
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
                // Silently fail
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
