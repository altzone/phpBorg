<?php

declare(strict_types=1);

namespace PhpBorg\Service\Queue;

use PhpBorg\Config\Configuration;
use PhpBorg\Entity\Job;
use PhpBorg\Exception\PhpBorgException;
use PhpBorg\Logger\LoggerInterface;
use PhpBorg\Repository\JobRepository;
use Redis;

/**
 * Job queue service using Redis
 */
final class JobQueue
{
    private const QUEUE_PREFIX = 'phpborg:queue:';
    private const DEFAULT_QUEUE = 'default';

    private Redis $redis;

    public function __construct(
        private readonly Configuration $config,
        private readonly JobRepository $jobRepository,
        private readonly LoggerInterface $logger
    ) {
        $this->connectRedis();
    }

    /**
     * Connect to Redis
     */
    private function connectRedis(): void
    {
        $this->redis = new Redis();

        $connected = $this->redis->connect(
            $this->config->redisHost ?? '127.0.0.1',
            $this->config->redisPort ?? 6379
        );

        if (!$connected) {
            throw new PhpBorgException('Failed to connect to Redis');
        }

        // Authenticate if password is set
        if (!empty($this->config->redisPassword)) {
            $this->redis->auth($this->config->redisPassword);
        }

        // Select database
        $this->redis->select($this->config->redisDatabase ?? 0);

        $this->logger->debug('Connected to Redis', 'QUEUE');
    }

    /**
     * Push a job to the queue
     */
    public function push(
        string $type,
        array $payload,
        string $queue = self::DEFAULT_QUEUE,
        int $maxAttempts = 3,
        ?int $createdBy = null
    ): int {
        // Create job in database
        $jobId = $this->jobRepository->create(
            type: $type,
            payload: $payload,
            queue: $queue,
            maxAttempts: $maxAttempts,
            createdBy: $createdBy
        );

        // Push job ID to Redis queue
        $queueKey = self::QUEUE_PREFIX . $queue;
        $this->redis->rPush($queueKey, (string) $jobId);

        $this->logger->info("Job #{$jobId} ({$type}) pushed to queue: {$queue}", 'QUEUE');

        return $jobId;
    }

    /**
     * Pop next job from queue
     */
    public function pop(string $queue = self::DEFAULT_QUEUE, int $timeout = 0): ?Job
    {
        $queueKey = self::QUEUE_PREFIX . $queue;

        // Blocking pop from Redis
        $result = $timeout > 0
            ? $this->redis->blPop([$queueKey], $timeout)
            : $this->redis->lPop($queueKey);

        if ($result === false || $result === null || $result === []) {
            return null;
        }

        // Extract job ID
        $jobId = is_array($result) && isset($result[1]) ? (int) $result[1] : (int) $result;

        // Get job from database
        $job = $this->jobRepository->findById($jobId);

        if ($job === null) {
            $this->logger->warning("Job #{$jobId} not found in database", 'QUEUE');
            return null;
        }

        $this->logger->info("Job #{$jobId} ({$job->type}) popped from queue: {$queue}", 'QUEUE');

        return $job;
    }

    /**
     * Get queue size
     */
    public function size(string $queue = self::DEFAULT_QUEUE): int
    {
        $queueKey = self::QUEUE_PREFIX . $queue;
        return $this->redis->lLen($queueKey);
    }

    /**
     * Clear queue
     */
    public function clear(string $queue = self::DEFAULT_QUEUE): void
    {
        $queueKey = self::QUEUE_PREFIX . $queue;
        $this->redis->del($queueKey);
        $this->logger->info("Queue cleared: {$queue}", 'QUEUE');
    }

    /**
     * Get all queue names
     */
    public function getQueues(): array
    {
        $keys = $this->redis->keys(self::QUEUE_PREFIX . '*');
        return array_map(
            fn($key) => str_replace(self::QUEUE_PREFIX, '', $key),
            $keys
        );
    }

    /**
     * Update job status
     */
    public function updateStatus(int $jobId, string $status, ?string $error = null): void
    {
        $this->jobRepository->updateStatus($jobId, $status, $error);
    }

    /**
     * Update job progress
     */
    public function updateProgress(int $jobId, int $progress, ?string $output = null): void
    {
        $this->jobRepository->updateProgress($jobId, $progress, $output);
    }

    /**
     * Mark job as running
     */
    public function markRunning(int $jobId, ?string $workerId = null): void
    {
        $this->jobRepository->updateStatus($jobId, 'running', null, $workerId);
        $this->logger->info("Job #{$jobId} marked as running", 'QUEUE');
    }

    /**
     * Mark job as completed
     */
    public function markCompleted(int $jobId, ?string $output = null): void
    {
        $this->jobRepository->updateStatus($jobId, 'completed');
        if ($output) {
            $this->jobRepository->updateProgress($jobId, 100, $output);
        }
        $this->logger->info("Job #{$jobId} completed", 'QUEUE');
    }

    /**
     * Mark job as failed
     */
    public function markFailed(int $jobId, string $error): void
    {
        $this->jobRepository->incrementAttempts($jobId);
        $this->jobRepository->updateStatus($jobId, 'failed', $error);
        $this->logger->error("Job #{$jobId} failed: {$error}", 'QUEUE');
    }

    /**
     * Cancel a job
     */
    public function cancel(int $jobId): bool
    {
        $job = $this->jobRepository->findById($jobId);

        if ($job === null) {
            return false;
        }

        // Can only cancel pending or running jobs
        if ($job->isFinished()) {
            return false;
        }

        $this->jobRepository->updateStatus($jobId, 'cancelled');
        $this->logger->info("Job #{$jobId} cancelled", 'QUEUE');

        return true;
    }

    /**
     * Retry a failed job
     */
    public function retry(int $jobId): bool
    {
        $job = $this->jobRepository->findById($jobId);

        if ($job === null || !$job->canRetry()) {
            return false;
        }

        // Reset status and push back to queue
        $this->jobRepository->updateStatus($jobId, 'pending');
        $this->redis->rPush(self::QUEUE_PREFIX . $job->queue, (string) $jobId);

        $this->logger->info("Job #{$jobId} retried", 'QUEUE');

        return true;
    }

    /**
     * Get job by ID
     */
    public function getJob(int $jobId): ?Job
    {
        return $this->jobRepository->findById($jobId);
    }

    /**
     * Get recent jobs
     */
    public function getRecentJobs(int $limit = 50): array
    {
        return $this->jobRepository->findAll($limit);
    }

    /**
     * Get queue statistics
     */
    public function getStats(): array
    {
        $dbStats = $this->jobRepository->getStats();

        $queueSizes = [];
        foreach ($this->getQueues() as $queue) {
            $queueSizes[$queue] = $this->size($queue);
        }

        return [
            'database' => $dbStats,
            'queues' => $queueSizes,
            'queue_total' => array_sum($queueSizes),
        ];
    }

    /**
     * Set real-time progress info in Redis (ephemeral, fast access)
     * Used for live updates in UI without hitting database
     *
     * @param int $jobId Job ID
     * @param array $progressData Progress data (files, bytes, percentage, etc.)
     * @param int $ttl Time to live in seconds (default: 1 hour)
     */
    public function setProgressInfo(int $jobId, array $progressData, int $ttl = 3600): void
    {
        $key = "phpborg:job:{$jobId}:progress";
        $this->redis->setex($key, $ttl, json_encode($progressData));
    }

    /**
     * Get real-time progress info from Redis
     *
     * @param int $jobId Job ID
     * @return array|null Progress data or null if not found/expired
     */
    public function getProgressInfo(int $jobId): ?array
    {
        $key = "phpborg:job:{$jobId}:progress";
        $data = $this->redis->get($key);

        if ($data === false) {
            return null;
        }

        $decoded = json_decode($data, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Delete progress info from Redis (cleanup after job completion)
     *
     * @param int $jobId Job ID
     */
    public function deleteProgressInfo(int $jobId): void
    {
        $key = "phpborg:job:{$jobId}:progress";
        $this->redis->del($key);
    }

    /**
     * Close Redis connection
     */
    public function close(): void
    {
        $this->redis->close();
    }
}
