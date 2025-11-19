<?php

declare(strict_types=1);

namespace PhpBorg\Service\Queue;

use PhpBorg\Logger\LoggerInterface;
use PhpBorg\Repository\BackupJobRepository;
use PhpBorg\Repository\BorgRepositoryRepository;
use PhpBorg\Repository\ServerRepository;
use PhpBorg\Repository\SettingRepository;
use PhpBorg\Repository\StoragePoolRepository;
use DateTimeImmutable;
use Throwable;

/**
 * Scheduler worker - Handles periodic tasks:
 * - Check backup schedules every 60 seconds
 * - Collect server stats (interval configurable in settings)
 * - Collect storage pool stats (interval configurable in settings)
 */
final class SchedulerWorker
{
    private bool $shouldStop = false;
    private int $lastStatsCollection = 0;
    private int $statsCollectionInterval = 900; // Default: 15 minutes

    // Check schedules every 60 seconds
    private const SCHEDULE_CHECK_INTERVAL = 60;

    // Default stats collection interval (15 minutes = 900 seconds)
    private const DEFAULT_STATS_COLLECTION_INTERVAL = 900;

    public function __construct(
        private readonly JobQueue $queue,
        private readonly BackupJobRepository $jobRepository,
        private readonly BorgRepositoryRepository $repositoryRepository,
        private readonly ServerRepository $serverRepository,
        private readonly StoragePoolRepository $storagePoolRepository,
        private readonly SettingRepository $settingRepository,
        private readonly LoggerInterface $logger
    ) {
        // Load stats collection interval from settings
        $this->loadStatsInterval();
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

                // Collect stats based on configured interval
                if ($now - $this->lastStatsCollection >= $this->statsCollectionInterval) {
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

            $this->logger->info(sprintf('Schedule check: found %d due job(s)', count($dueJobs)), 'SCHEDULER');

            if (empty($dueJobs)) {
                return;
            }

            $this->logger->info(sprintf('Found %d due backup job(s)', count($dueJobs)), 'SCHEDULER');

            foreach ($dueJobs as $job) {
                try {
                    // Get repository to find server_id
                    $repository = $this->repositoryRepository->findById($job->repositoryId);
                    if (!$repository) {
                        $this->logger->error(
                            sprintf('Repository #%d not found for backup job #%d', $job->repositoryId, $job->id),
                            'SCHEDULER'
                        );
                        continue;
                    }

                    // Get server name for display
                    $server = $this->serverRepository->findById($repository->serverId);
                    $serverName = $server ? $server->name : "Server #{$repository->serverId}";

                    // Create backup job in queue
                    $jobId = $this->queue->push(
                        type: 'backup_create',
                        payload: [
                            'server_id' => $repository->serverId,
                            'server_name' => $serverName,
                            'backup_job_id' => $job->id,
                            'repository_id' => $job->repositoryId,
                            'repository_name' => $job->name, // Use backup job name
                            'type' => $repository->type ?? 'backup',
                            'scheduled' => true,
                            'triggered_by' => 'scheduled',
                        ],
                        queue: 'default',
                        maxAttempts: 3,
                        createdBy: null // Scheduled jobs have no user
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
            $servers = $this->serverRepository->findAllActive();

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
                        maxAttempts: 2,
                        createdBy: null
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
                        maxAttempts: 2,
                        createdBy: null
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

    /**
     * Load stats collection interval from settings
     */
    private function loadStatsInterval(): void
    {
        try {
            $setting = $this->settingRepository->findByKey('system.stats_collection_interval');

            if ($setting !== null) {
                $value = (int) $setting->value;
                // Ensure minimum interval of 60 seconds
                $this->statsCollectionInterval = max(60, $value);

                $this->logger->info(
                    sprintf('Stats collection interval set to %d seconds (%d minutes)',
                        $this->statsCollectionInterval,
                        round($this->statsCollectionInterval / 60)
                    ),
                    'SCHEDULER'
                );
            } else {
                $this->statsCollectionInterval = self::DEFAULT_STATS_COLLECTION_INTERVAL;
                $this->logger->warning(
                    'Stats collection interval setting not found, using default: ' . self::DEFAULT_STATS_COLLECTION_INTERVAL . ' seconds',
                    'SCHEDULER'
                );
            }
        } catch (Throwable $e) {
            $this->statsCollectionInterval = self::DEFAULT_STATS_COLLECTION_INTERVAL;
            $this->logger->error(
                'Failed to load stats collection interval setting: ' . $e->getMessage(),
                'SCHEDULER'
            );
        }
    }
}
