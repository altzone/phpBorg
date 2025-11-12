<?php

declare(strict_types=1);

namespace PhpBorg\Service\Queue;

use PhpBorg\Logger\LoggerInterface;
use PhpBorg\Repository\BackupJobRepository;
use PhpBorg\Repository\ServerRepository;
use PhpBorg\Repository\StoragePoolRepository;
use DateTimeImmutable;
use Throwable;

/**
 * Scheduler worker - Handles periodic tasks:
 * - Check backup schedules every 60 seconds
 * - Collect server stats every 15 minutes
 * - Collect storage pool stats every 15 minutes
 */
final class SchedulerWorker
{
    private bool $shouldStop = false;
    private int $lastStatsCollection = 0;

    // Check schedules every 60 seconds
    private const SCHEDULE_CHECK_INTERVAL = 60;

    // Collect stats every 15 minutes (900 seconds)
    private const STATS_COLLECTION_INTERVAL = 900;

    public function __construct(
        private readonly JobQueue $queue,
        private readonly BackupJobRepository $jobRepository,
        private readonly ServerRepository $serverRepository,
        private readonly StoragePoolRepository $storagePoolRepository,
        private readonly LoggerInterface $logger
    ) {
        // Register signal handlers for graceful shutdown
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'stop']);
            pcntl_signal(SIGINT, [$this, 'stop']);
        }
    }

    /**
     * Start the scheduler worker
     */
    public function start(): void
    {
        $this->logger->info('Scheduler worker started', 'SCHEDULER');

        $lastScheduleCheck = 0;

        while (!$this->shouldStop) {
            try {
                // Process signals
                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }

                $now = time();

                // Check schedules every 60 seconds
                if ($now - $lastScheduleCheck >= self::SCHEDULE_CHECK_INTERVAL) {
                    $this->checkSchedules();
                    $lastScheduleCheck = $now;
                }

                // Collect stats every 15 minutes
                if ($now - $this->lastStatsCollection >= self::STATS_COLLECTION_INTERVAL) {
                    $this->collectStats();
                    $this->lastStatsCollection = $now;
                }

                // Sleep for 1 second before next iteration
                sleep(1);

            } catch (Throwable $e) {
                $this->logger->error("Scheduler worker error: {$e->getMessage()}", 'SCHEDULER');
                sleep(5); // Brief pause before continuing on error
            }
        }

        $this->logger->info('Scheduler worker stopped', 'SCHEDULER');
    }

    /**
     * Check all backup schedules and queue jobs that are due
     */
    private function checkSchedules(): void
    {
        try {
            // Get all enabled backup jobs with next_run_at <= NOW
            $dueJobs = $this->jobRepository->findDueJobs();

            if (empty($dueJobs)) {
                return;
            }

            $this->logger->info(sprintf('Found %d due backup job(s)', count($dueJobs)), 'SCHEDULER');

            foreach ($dueJobs as $job) {
                try {
                    // Create backup job in queue
                    $jobId = $this->queue->push(
                        type: 'backup_create',
                        payload: [
                            'backup_job_id' => $job->id,
                            'repository_id' => $job->repositoryId,
                            'source_id' => $job->sourceId,
                            'scheduled' => true,
                        ],
                        queue: 'default',
                        priority: $this->mapPriority($job->priority ?? 'normal'),
                        userId: null // Scheduled jobs have no user
                    );

                    $this->logger->info(
                        sprintf('Queued backup job #%d as queue job #%d', $job->id, $jobId),
                        'SCHEDULER'
                    );

                    // Update last_run_at and recalculate next_run_at
                    $this->jobRepository->updateLastRun($job->id);

                } catch (Throwable $e) {
                    $this->logger->error(
                        sprintf('Failed to queue backup job #%d: %s', $job->id, $e->getMessage()),
                        'SCHEDULER'
                    );
                }
            }

        } catch (Throwable $e) {
            $this->logger->error("Failed to check schedules: {$e->getMessage()}", 'SCHEDULER');
        }
    }

    /**
     * Collect stats from all servers and storage pools
     */
    private function collectStats(): void
    {
        $this->logger->info('Starting periodic stats collection', 'SCHEDULER');

        try {
            // Collect server stats
            $servers = $this->serverRepository->findActive();

            foreach ($servers as $server) {
                try {
                    $this->queue->push(
                        type: 'server_stats_collect',
                        payload: [
                            'server_id' => $server->id,
                            'server_name' => $server->name,
                            'scheduled' => true,
                        ],
                        queue: 'default',
                        priority: 1, // Low priority
                        userId: null
                    );

                    $this->logger->debug(
                        sprintf('Queued stats collection for server #%d (%s)', $server->id, $server->name),
                        'SCHEDULER'
                    );

                } catch (Throwable $e) {
                    $this->logger->error(
                        sprintf('Failed to queue stats for server #%d: %s', $server->id, $e->getMessage()),
                        'SCHEDULER'
                    );
                }
            }

            // Collect storage pool stats
            $pools = $this->storagePoolRepository->findActive();

            foreach ($pools as $pool) {
                try {
                    $this->queue->push(
                        type: 'storage_pool_analyze',
                        payload: [
                            'pool_id' => $pool->id,
                            'pool_name' => $pool->name,
                            'pool_path' => $pool->path,
                            'scheduled' => true,
                        ],
                        queue: 'default',
                        priority: 1, // Low priority
                        userId: null
                    );

                    $this->logger->debug(
                        sprintf('Queued stats collection for storage pool #%d (%s)', $pool->id, $pool->name),
                        'SCHEDULER'
                    );

                } catch (Throwable $e) {
                    $this->logger->error(
                        sprintf('Failed to queue stats for pool #%d: %s', $pool->id, $e->getMessage()),
                        'SCHEDULER'
                    );
                }
            }

            $this->logger->info(
                sprintf(
                    'Queued stats collection for %d servers and %d storage pools',
                    count($servers),
                    count($pools)
                ),
                'SCHEDULER'
            );

        } catch (Throwable $e) {
            $this->logger->error("Failed to collect stats: {$e->getMessage()}", 'SCHEDULER');
        }
    }

    /**
     * Map job priority to queue priority
     */
    private function mapPriority(string $priority): int
    {
        return match ($priority) {
            'critical' => 5,
            'high' => 4,
            'normal' => 3,
            'low' => 2,
            default => 3,
        };
    }

    /**
     * Stop the worker gracefully
     */
    public function stop(): void
    {
        $this->logger->info('Scheduler worker stop signal received', 'SCHEDULER');
        $this->shouldStop = true;
    }

    /**
     * Check if worker should stop
     */
    public function shouldStop(): bool
    {
        return $this->shouldStop;
    }
}
