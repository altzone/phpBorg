<?php

declare(strict_types=1);

namespace PhpBorg\Api\Controller;

use PhpBorg\Application;
use PhpBorg\Repository\JobRepository;
use PhpBorg\Repository\InstantRecoverySessionRepository;
use PhpBorg\Repository\ArchiveRepository;
use PhpBorg\Repository\ServerRepository;
use PhpBorg\Service\Queue\JobQueue;

/**
 * Global SSE Controller
 * Streams real-time updates for all topics: workers, jobs, backups, stats
 */
class SSEController extends BaseController
{
    private readonly JobQueue $jobQueue;
    private readonly JobRepository $jobRepo;
    private readonly InstantRecoverySessionRepository $sessionRepo;
    private readonly ArchiveRepository $archiveRepo;
    private readonly ServerRepository $serverRepo;

    public function __construct(Application $app)
    {
        $this->jobQueue = $app->getJobQueue();
        $this->jobRepo = $app->getJobRepository();
        $this->sessionRepo = $app->getInstantRecoverySessionRepository();
        $this->archiveRepo = $app->getArchiveRepository();
        $this->serverRepo = $app->getServerRepository();
    }

    /**
     * GET /api/sse/stream?token=xxx
     * Global SSE stream for all real-time updates
     */
    public function stream(): void
    {
        // Set SSE headers
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no'); // Disable nginx buffering

        // Verify token from query string (SSE can't use Authorization header)
        $token = $_GET['token'] ?? null;
        if (!$token) {
            echo "event: error\n";
            echo "data: " . json_encode(['error' => 'Missing token']) . "\n\n";
            flush();
            return;
        }

        // Validate JWT token
        try {
            $app = new Application();
            $jwtService = $app->getJwtService();
            $payload = $jwtService->validateAccessToken($token);

            if (!$payload || !isset($payload['sub'])) {
                echo "event: error\n";
                echo "data: " . json_encode(['error' => 'Invalid token']) . "\n\n";
                flush();
                return;
            }
        } catch (\Exception $e) {
            echo "event: error\n";
            echo "data: " . json_encode(['error' => 'Token verification failed: ' . $e->getMessage()]) . "\n\n";
            flush();
            return;
        }

        // Track last sent data to avoid duplicates
        $lastJobsHash = null;
        $lastWorkersHash = null;
        $lastRecoveryHash = null;

        // Stream loop
        $startTime = time();
        $maxDuration = 900; // 15 minutes max (before token expires)

        while (true) {
            // Check if we should stop (max duration or connection closed)
            if (time() - $startTime > $maxDuration) {
                break;
            }

            if (connection_aborted()) {
                break;
            }

            // Send heartbeat every 15 seconds
            if (time() % 15 === 0) {
                echo "event: heartbeat\n";
                echo "data: " . json_encode(['timestamp' => time()]) . "\n\n";
                flush();
            }

            // JOBS: Get all jobs and send if changed
            try {
                $jobs = $this->jobRepo->findAll();
                $stats = $this->jobRepo->getStats();

                $jobsData = [
                    'jobs' => array_map(fn($job) => $job->toArray(), $jobs),
                    'stats' => $stats,
                    'timestamp' => time()
                ];

                $jobsHash = md5(json_encode($jobsData));

                if ($jobsHash !== $lastJobsHash) {
                    echo "event: jobs\n";
                    echo "data: " . json_encode($jobsData) . "\n\n";
                    flush();
                    $lastJobsHash = $jobsHash;
                }

                // Real-time job progress (from Redis)
                foreach ($jobs as $job) {
                    if ($job->status === 'running') {
                        $progressInfo = $this->jobQueue->getProgressInfo($job->id);
                        if ($progressInfo) {
                            echo "event: jobs\n";
                            echo "data: " . json_encode([
                                'job_id' => $job->id,
                                'progress_info' => $progressInfo,
                                'timestamp' => time()
                            ]) . "\n\n";
                            flush();
                        }
                    }
                }
            } catch (\Exception $e) {
                // Log error but continue
                error_log("SSE jobs error: " . $e->getMessage());
            }

            // WORKERS: Stream worker status
            try {
                $workers = [];

                // Get scheduler status
                $workers[] = $this->getServiceStatus('phpborg-scheduler');

                // Get worker pool status (1 to 4)
                for ($i = 1; $i <= 4; $i++) {
                    $workers[] = $this->getServiceStatus("phpborg-worker@{$i}");
                }

                $workersData = [
                    'workers' => $workers,
                    'total' => count($workers),
                ];

                $workersHash = md5(json_encode($workersData));

                if ($workersHash !== $lastWorkersHash) {
                    echo "event: workers\n";
                    echo "data: " . json_encode($workersData) . "\n\n";
                    flush();
                    $lastWorkersHash = $workersHash;
                }
            } catch (\Exception $e) {
                error_log("SSE workers error: " . $e->getMessage());
            }

            // INSTANT RECOVERY: Stream active sessions
            try {
                $sessions = $this->sessionRepo->findActive();

                // Enrich each session with server and archive info (same logic as InstantRecoveryController)
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

                $recoveryData = [
                    'sessions' => $enrichedSessions,
                    'count' => count($enrichedSessions),
                    'timestamp' => time()
                ];

                $recoveryHash = md5(json_encode($recoveryData));

                if ($recoveryHash !== $lastRecoveryHash) {
                    echo "event: instant_recovery\n";
                    echo "data: " . json_encode($recoveryData) . "\n\n";
                    flush();
                    $lastRecoveryHash = $recoveryHash;
                }
            } catch (\Exception $e) {
                error_log("SSE instant recovery error: " . $e->getMessage());
            }

            // TODO: Add more event types
            // - event: backups (new backup created/deleted)
            // - event: stats (server/pool stats updated)

            // Sleep to avoid hammering the server
            sleep(1);
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
            if (preg_match('/Memory:\s*([\d.]+[KMGT]?B?)/', $line, $matches)) {
                $memory = $matches[1];
            }
            if (preg_match('/CPU:\s*([\d.]+[a-z]+)/', $line, $matches)) {
                $cpu = $matches[1];
            }
            if (preg_match('/Main PID:\s*(\d+)/', $line, $matches)) {
                $pid = (int)$matches[1];
            }
            if (preg_match('/Active:\s*active\s*\(running\)\s*since\s+(.+);\s*(.+)$/', $line, $matches)) {
                $uptime = trim($matches[2]);
            }
        }

        // Get current running job for worker pool instances
        $currentJob = null;
        if (strpos($serviceName, 'phpborg-worker@') === 0) {
            try {
                preg_match('/phpborg-worker@(\d+)/', $serviceName, $matches);
                if (isset($matches[1])) {
                    $workerTag = "WORKER #{$matches[1]}";
                    $runningJobs = $this->jobRepo->findByStatus('running');
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
