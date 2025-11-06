<?php

declare(strict_types=1);

namespace PhpBorg\Entity;

use DateTime;

/**
 * Job entity for background tasks
 */
final readonly class Job
{
    public function __construct(
        public int $id,
        public string $queue,
        public string $type,
        public array $payload,
        public string $status,
        public int $progress,
        public int $attempts,
        public int $maxAttempts,
        public ?string $output,
        public ?string $error,
        public ?DateTime $startedAt,
        public ?DateTime $completedAt,
        public DateTime $createdAt,
        public ?int $createdBy,
    ) {
    }

    /**
     * Convert to array for API response
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'queue' => $this->queue,
            'type' => $this->type,
            'payload' => $this->payload,
            'status' => $this->status,
            'progress' => $this->progress,
            'attempts' => $this->attempts,
            'max_attempts' => $this->maxAttempts,
            'output' => $this->output,
            'error' => $this->error,
            'started_at' => $this->startedAt?->format('Y-m-d H:i:s'),
            'completed_at' => $this->completedAt?->format('Y-m-d H:i:s'),
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'created_by' => $this->createdBy,
        ];
    }

    /**
     * Check if job is pending
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if job is running
     */
    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    /**
     * Check if job is completed
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Check if job has failed
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Check if job is cancelled
     */
    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    /**
     * Check if job is finished (completed, failed, or cancelled)
     */
    public function isFinished(): bool
    {
        return in_array($this->status, ['completed', 'failed', 'cancelled']);
    }

    /**
     * Check if job can be retried
     */
    public function canRetry(): bool
    {
        return $this->isFailed() && $this->attempts < $this->maxAttempts;
    }
}
