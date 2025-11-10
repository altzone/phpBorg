<?php

declare(strict_types=1);

namespace PhpBorg\Repository;

use DateTime;
use PhpBorg\Database\Connection;
use PhpBorg\Entity\Job;
use PhpBorg\Exception\DatabaseException;

/**
 * Job repository
 */
final class JobRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * Create a new job
     */
    public function create(
        string $type,
        array $payload,
        string $queue = 'default',
        int $maxAttempts = 3,
        ?int $createdBy = null
    ): int {
        $this->connection->executeUpdate(
            'INSERT INTO jobs (queue, type, payload, status, progress, attempts, max_attempts, created_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $queue,
                $type,
                json_encode($payload),
                'pending',
                0,
                0,
                $maxAttempts,
                (new DateTime())->format('Y-m-d H:i:s'),
                $createdBy
            ]
        );

        return $this->connection->getLastInsertId();
    }

    /**
     * Find job by ID
     */
    public function findById(int $id): ?Job
    {
        $row = $this->connection->fetchOne(
            'SELECT * FROM jobs WHERE id = ?',
            [$id]
        );

        return $row ? $this->hydrate($row) : null;
    }

    /**
     * Find all jobs
     */
    public function findAll(int $limit = 100, int $offset = 0): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT * FROM jobs ORDER BY created_at DESC LIMIT ? OFFSET ?',
            [$limit, $offset]
        );

        return array_map([$this, 'hydrate'], $rows);
    }

    /**
     * Find jobs by status
     */
    public function findByStatus(string $status, int $limit = 100): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT * FROM jobs WHERE status = ? ORDER BY created_at DESC LIMIT ?',
            [$status, $limit]
        );

        return array_map([$this, 'hydrate'], $rows);
    }

    /**
     * Find jobs by type
     */
    public function findByType(string $type, int $limit = 100): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT * FROM jobs WHERE type = ? ORDER BY created_at DESC LIMIT ?',
            [$type, $limit]
        );

        return array_map([$this, 'hydrate'], $rows);
    }

    /**
     * Update job status
     */
    public function updateStatus(int $id, string $status, ?string $error = null): void
    {
        $fields = ['status = ?'];
        $params = [$status];

        if ($status === 'running') {
            $fields[] = 'started_at = ?';
            $params[] = (new DateTime())->format('Y-m-d H:i:s');
        } elseif (in_array($status, ['completed', 'failed', 'cancelled'])) {
            $fields[] = 'completed_at = ?';
            $params[] = (new DateTime())->format('Y-m-d H:i:s');
        }

        if ($error !== null) {
            $fields[] = 'error = ?';
            $params[] = $error;
        }

        $params[] = $id;

        $this->connection->executeUpdate(
            'UPDATE jobs SET ' . implode(', ', $fields) . ' WHERE id = ?',
            $params
        );
    }

    /**
     * Update job progress
     */
    public function updateProgress(int $id, int $progress, ?string $output = null): void
    {
        $fields = ['progress = ?'];
        $params = [$progress];

        if ($output !== null) {
            // If progress is 100 and output starts with '{', it's the final JSON result
            // Replace the output instead of concatenating
            if ($progress === 100 && strlen($output) > 0 && $output[0] === '{') {
                $fields[] = 'output = ?';
                $params[] = $output;
            } else {
                // Otherwise concatenate progress messages
                $fields[] = 'output = CONCAT(COALESCE(output, ""), ?)';
                $params[] = $output . "\n";
            }
        }

        $params[] = $id;

        $this->connection->executeUpdate(
            'UPDATE jobs SET ' . implode(', ', $fields) . ' WHERE id = ?',
            $params
        );
    }

    /**
     * Increment attempts
     */
    public function incrementAttempts(int $id): void
    {
        $this->connection->executeUpdate(
            'UPDATE jobs SET attempts = attempts + 1 WHERE id = ?',
            [$id]
        );
    }

    /**
     * Delete old completed jobs
     */
    public function deleteOldCompleted(int $daysOld = 30): int
    {
        $date = (new DateTime())->modify("-{$daysOld} days")->format('Y-m-d H:i:s');

        $this->connection->executeUpdate(
            'DELETE FROM jobs WHERE status IN (?, ?) AND completed_at < ?',
            ['completed', 'failed', $date]
        );

        return $this->connection->getAffectedRows();
    }

    /**
     * Get job statistics
     */
    public function getStats(): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT status, COUNT(*) as count FROM jobs GROUP BY status'
        );

        $stats = [
            'pending' => 0,
            'running' => 0,
            'completed' => 0,
            'failed' => 0,
            'cancelled' => 0,
        ];

        foreach ($rows as $row) {
            $stats[$row['status']] = (int) $row['count'];
        }

        $stats['total'] = array_sum($stats);

        return $stats;
    }

    /**
     * Hydrate job from database row
     */
    private function hydrate(array $row): Job
    {
        return new Job(
            id: (int) $row['id'],
            queue: $row['queue'],
            type: $row['type'],
            payload: json_decode($row['payload'], true) ?? [],
            status: $row['status'],
            progress: (int) $row['progress'],
            attempts: (int) $row['attempts'],
            maxAttempts: (int) $row['max_attempts'],
            output: $row['output'],
            error: $row['error'],
            startedAt: $row['started_at'] ? new DateTime($row['started_at']) : null,
            completedAt: $row['completed_at'] ? new DateTime($row['completed_at']) : null,
            createdAt: new DateTime($row['created_at']),
            createdBy: $row['created_by'] ? (int) $row['created_by'] : null,
        );
    }
}
