<?php

declare(strict_types=1);

namespace PhpBorg\Api\Controller;

use PhpBorg\Application;
use PhpBorg\Repository\JobRepository;
use PhpBorg\Service\Queue\JobQueue;

/**
 * Global SSE Controller
 * Streams real-time updates for all topics: workers, jobs, backups, stats
 */
class SSEController extends BaseController
{
    private readonly JobQueue $jobQueue;
    private readonly JobRepository $jobRepo;

    public function __construct(Application $app)
    {
        $this->jobQueue = $app->getJobQueue();
        $this->jobRepo = $app->getJobRepository();
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
            $payload = $jwtService->verify($token);

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

            // WORKERS: Stream worker status (use existing WorkerController logic)
            try {
                $app = new Application();
                $workerController = new \PhpBorg\Api\Controller\WorkerController($app);

                // Get workers data (reuse existing method)
                ob_start();
                $workerController->list();
                $output = ob_get_clean();

                $workersData = json_decode($output, true);

                if ($workersData && isset($workersData['data'])) {
                    $workersHash = md5(json_encode($workersData['data']));

                    if ($workersHash !== $lastWorkersHash) {
                        echo "event: workers\n";
                        echo "data: " . json_encode($workersData['data']) . "\n\n";
                        flush();
                        $lastWorkersHash = $workersHash;
                    }
                }
            } catch (\Exception $e) {
                error_log("SSE workers error: " . $e->getMessage());
            }

            // TODO: Add more event types
            // - event: backups (new backup created/deleted)
            // - event: stats (server/pool stats updated)

            // Sleep to avoid hammering the server
            sleep(1);
        }
    }
}
