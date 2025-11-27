<?php

declare(strict_types=1);

namespace PhpBorg\Repository;

use PhpBorg\Database\Connection;
use PhpBorg\Exception\DatabaseException;

/**
 * Repository for Agent Task entities
 */
final class AgentTaskRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * Find task by ID
     */
    public function findById(int $id): ?array
    {
        return $this->connection->fetchOne(
            'SELECT * FROM agent_tasks WHERE id = ?',
            [$id]
        );
    }

    /**
     * Find pending tasks for an agent
     * Returns tasks ordered by priority and creation time
     */
    public function findPendingForAgent(int $agentId, int $limit = 10): array
    {
        return $this->connection->fetchAll(
            "SELECT * FROM agent_tasks
             WHERE agent_id = ?
             AND status = 'pending'
             AND (retry_after IS NULL OR retry_after <= NOW())
             ORDER BY
               FIELD(priority, 'critical', 'high', 'normal', 'low'),
               created_at ASC
             LIMIT ?",
            [$agentId, $limit]
        );
    }

    /**
     * Find running tasks for an agent
     */
    public function findRunningForAgent(int $agentId): array
    {
        return $this->connection->fetchAll(
            "SELECT * FROM agent_tasks
             WHERE agent_id = ?
             AND status IN ('assigned', 'running')
             ORDER BY started_at",
            [$agentId]
        );
    }

    /**
     * Find tasks by job ID
     */
    public function findByJobId(int $jobId): array
    {
        return $this->connection->fetchAll(
            'SELECT * FROM agent_tasks WHERE job_id = ? ORDER BY created_at',
            [$jobId]
        );
    }

    /**
     * Find all tasks for an agent
     */
    public function findAllForAgent(int $agentId, int $limit = 100): array
    {
        return $this->connection->fetchAll(
            'SELECT * FROM agent_tasks
             WHERE agent_id = ?
             ORDER BY created_at DESC
             LIMIT ?',
            [$agentId, $limit]
        );
    }

    /**
     * Create a new task
     */
    public function create(
        int $agentId,
        string $type,
        array $payload,
        string $priority = 'normal',
        ?int $jobId = null,
        ?int $createdBy = null,
        int $timeoutSeconds = 3600
    ): int {
        $this->connection->executeUpdate(
            'INSERT INTO agent_tasks
             (agent_id, job_id, type, priority, payload, status, timeout_seconds, created_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)',
            [
                $agentId,
                $jobId,
                $type,
                $priority,
                json_encode($payload),
                'pending',
                $timeoutSeconds,
                $createdBy,
            ]
        );

        return $this->connection->getLastInsertId();
    }

    /**
     * Assign task to agent (mark as assigned)
     */
    public function assignTask(int $taskId): bool
    {
        $affected = $this->connection->executeUpdate(
            "UPDATE agent_tasks
             SET status = 'assigned', assigned_at = NOW()
             WHERE id = ? AND status = 'pending'",
            [$taskId]
        );

        return $affected > 0;
    }

    /**
     * Mark task as running
     */
    public function markRunning(int $taskId): void
    {
        $this->connection->executeUpdate(
            "UPDATE agent_tasks
             SET status = 'running', started_at = NOW()
             WHERE id = ?",
            [$taskId]
        );
    }

    /**
     * Update task progress
     */
    public function updateProgress(int $taskId, int $progress, ?string $message = null): void
    {
        $sql = 'UPDATE agent_tasks SET progress = ?';
        $params = [$progress];

        if ($message !== null) {
            $sql .= ', progress_message = ?';
            $params[] = $message;
        }

        $sql .= ' WHERE id = ?';
        $params[] = $taskId;

        $this->connection->executeUpdate($sql, $params);
    }

    /**
     * Mark task as completed
     */
    public function markCompleted(int $taskId, ?array $result = null, ?int $exitCode = 0): void
    {
        $this->connection->executeUpdate(
            "UPDATE agent_tasks
             SET status = 'completed',
                 completed_at = NOW(),
                 progress = 100,
                 result = ?,
                 exit_code = ?
             WHERE id = ?",
            [
                $result !== null ? json_encode($result) : null,
                $exitCode,
                $taskId,
            ]
        );
    }

    /**
     * Mark task as failed
     */
    public function markFailed(int $taskId, string $error, ?int $exitCode = null): void
    {
        // Get current task to check retry logic
        $task = $this->findById($taskId);
        $newAttempts = ($task['attempts'] ?? 0) + 1;
        $maxAttempts = $task['max_attempts'] ?? 3;

        if ($newAttempts < $maxAttempts) {
            // Schedule retry
            $retryDelay = min(300 * $newAttempts, 1800); // 5min, 10min, 15min... max 30min
            $this->connection->executeUpdate(
                "UPDATE agent_tasks
                 SET status = 'pending',
                     error = ?,
                     exit_code = ?,
                     attempts = ?,
                     retry_after = DATE_ADD(NOW(), INTERVAL ? SECOND)
                 WHERE id = ?",
                [$error, $exitCode, $newAttempts, $retryDelay, $taskId]
            );
        } else {
            // Final failure
            $this->connection->executeUpdate(
                "UPDATE agent_tasks
                 SET status = 'failed',
                     completed_at = NOW(),
                     error = ?,
                     exit_code = ?,
                     attempts = ?
                 WHERE id = ?",
                [$error, $exitCode, $newAttempts, $taskId]
            );
        }
    }

    /**
     * Cancel task
     */
    public function cancel(int $taskId): void
    {
        $this->connection->executeUpdate(
            "UPDATE agent_tasks
             SET status = 'cancelled', completed_at = NOW()
             WHERE id = ? AND status IN ('pending', 'assigned')",
            [$taskId]
        );
    }

    /**
     * Cancel all pending tasks for an agent
     */
    public function cancelAllPendingForAgent(int $agentId): int
    {
        return $this->connection->executeUpdate(
            "UPDATE agent_tasks
             SET status = 'cancelled', completed_at = NOW()
             WHERE agent_id = ? AND status IN ('pending', 'assigned')",
            [$agentId]
        );
    }

    /**
     * Find timed out tasks (running tasks that exceeded timeout)
     */
    public function findTimedOutTasks(): array
    {
        return $this->connection->fetchAll(
            "SELECT * FROM agent_tasks
             WHERE status = 'running'
             AND started_at IS NOT NULL
             AND TIMESTAMPDIFF(SECOND, started_at, NOW()) > timeout_seconds"
        );
    }

    /**
     * Find stale assigned tasks (assigned but never started)
     */
    public function findStaleAssignedTasks(int $secondsSinceAssigned = 60): array
    {
        return $this->connection->fetchAll(
            "SELECT * FROM agent_tasks
             WHERE status = 'assigned'
             AND assigned_at IS NOT NULL
             AND TIMESTAMPDIFF(SECOND, assigned_at, NOW()) > ?",
            [$secondsSinceAssigned]
        );
    }

    /**
     * Reset stale assigned tasks back to pending
     */
    public function resetStaleAssignedTasks(int $secondsSinceAssigned = 60): int
    {
        return $this->connection->executeUpdate(
            "UPDATE agent_tasks
             SET status = 'pending', assigned_at = NULL
             WHERE status = 'assigned'
             AND assigned_at IS NOT NULL
             AND TIMESTAMPDIFF(SECOND, assigned_at, NOW()) > ?",
            [$secondsSinceAssigned]
        );
    }

    /**
     * Get task statistics for an agent
     */
    public function getStatsForAgent(int $agentId): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT status, COUNT(*) as count FROM agent_tasks WHERE agent_id = ? GROUP BY status',
            [$agentId]
        );

        $stats = [
            'pending' => 0,
            'assigned' => 0,
            'running' => 0,
            'completed' => 0,
            'failed' => 0,
            'cancelled' => 0,
        ];

        foreach ($rows as $row) {
            $stats[$row['status']] = (int) $row['count'];
        }

        return $stats;
    }

    /**
     * Delete old completed/failed tasks
     */
    public function deleteOldTasks(int $daysOld = 30): int
    {
        return $this->connection->executeUpdate(
            "DELETE FROM agent_tasks
             WHERE status IN ('completed', 'failed', 'cancelled')
             AND completed_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$daysOld]
        );
    }
}
