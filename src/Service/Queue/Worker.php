<?php

declare(strict_types=1);

namespace PhpBorg\Service\Queue;

use PhpBorg\Entity\Job;
use PhpBorg\Exception\PhpBorgException;
use PhpBorg\Logger\LoggerInterface;
use PhpBorg\Service\Queue\Handlers\JobHandlerInterface;
use Throwable;

/**
 * Background worker to process jobs from queue
 */
final class Worker
{
    private bool $shouldStop = false;

    /** @var array<string, JobHandlerInterface> */
    private array $handlers = [];

    private readonly string $workerTag;

    public function __construct(
        private readonly JobQueue $queue,
        private readonly LoggerInterface $logger,
        ?string $workerId = null
    ) {
        // Create worker tag for logging
        $this->workerTag = $workerId ? "WORKER #{$workerId}" : 'WORKER';

        // Register signal handlers for graceful shutdown
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'stop']);
            pcntl_signal(SIGINT, [$this, 'stop']);
        }
    }

    /**
     * Register a job handler
     */
    public function registerHandler(string $jobType, JobHandlerInterface $handler): void
    {
        $this->handlers[$jobType] = $handler;
        $this->logger->info("Handler registered for job type: {$jobType}", $this->workerTag);
    }

    /**
     * Start the worker
     */
    public function start(string $queueName = 'default'): void
    {
        $this->logger->info("Worker started for queue: {$queueName}", $this->workerTag);

        while (!$this->shouldStop) {
            try {
                // Process signals
                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }

                // Get next job from queue (5 second timeout)
                $job = $this->queue->pop($queueName, 5);

                if ($job === null) {
                    continue;
                }

                $this->processJob($job);

            } catch (Throwable $e) {
                $this->logger->error("Worker error: {$e->getMessage()}", $this->workerTag);
                sleep(1); // Brief pause before continuing
            }
        }

        $this->logger->info('Worker stopped', $this->workerTag);
    }

    /**
     * Process a single job
     */
    private function processJob(Job $job): void
    {
        $this->logger->info("Processing job #{$job->id} ({$job->type})", $this->workerTag);

        // Check if job is already finished
        if ($job->isFinished()) {
            $this->logger->warning("Job #{$job->id} is already finished, skipping", $this->workerTag);
            return;
        }

        // Check if handler exists
        if (!isset($this->handlers[$job->type])) {
            $error = "No handler registered for job type: {$job->type}";
            $this->logger->error($error, $this->workerTag);
            $this->queue->markFailed($job->id, $error);
            return;
        }

        // Mark job as running
        $this->queue->markRunning($job->id, $this->workerTag);

        try {
            // Execute job handler
            $handler = $this->handlers[$job->type];
            $result = $handler->handle($job, $this->queue);

            // Mark job as completed
            $this->queue->markCompleted($job->id, $result);

            $this->logger->info("Job #{$job->id} completed successfully", $this->workerTag);

        } catch (Throwable $e) {
            $error = $e->getMessage();
            $this->logger->error("Job #{$job->id} failed: {$error}", $this->workerTag);

            // Mark job as failed
            $this->queue->markFailed($job->id, $error);

            // Retry if possible
            if ($job->canRetry()) {
                $this->logger->info("Job #{$job->id} will be retried", $this->workerTag);
                $this->queue->retry($job->id);
            }
        }
    }

    /**
     * Stop the worker gracefully
     */
    public function stop(): void
    {
        $this->logger->info('Worker stop signal received', $this->workerTag);
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
