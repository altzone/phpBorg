<?php

declare(strict_types=1);

namespace PhpBorg\Repository;

use DateTimeImmutable;
use PhpBorg\Database\Connection;
use PhpBorg\Entity\Report;
use PhpBorg\Exception\DatabaseException;

/**
 * Repository for Report entities
 */
final class ReportRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * Find report by ID
     *
     * @throws DatabaseException
     */
    public function findById(int $id): ?Report
    {
        $row = $this->connection->fetchOne(
            'SELECT * FROM report WHERE id = ?',
            [$id]
        );

        return $row ? Report::fromDatabase($row) : null;
    }

    /**
     * Find reports by server ID
     *
     * @return array<int, Report>
     * @throws DatabaseException
     */
    public function findByServerId(int $serverId): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT * FROM report WHERE server_id = ? ORDER BY start DESC',
            [$serverId]
        );

        return array_map(fn(array $row) => Report::fromDatabase($row), $rows);
    }

    /**
     * Find latest reports
     *
     * @return array<int, Report>
     * @throws DatabaseException
     */
    public function findLatest(int $limit = 10): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT * FROM report ORDER BY start DESC LIMIT ?',
            [$limit]
        );

        return array_map(fn(array $row) => Report::fromDatabase($row), $rows);
    }

    /**
     * Find running reports
     *
     * @return array<int, Report>
     * @throws DatabaseException
     */
    public function findRunning(): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT * FROM report WHERE end IS NULL ORDER BY start DESC'
        );

        return array_map(fn(array $row) => Report::fromDatabase($row), $rows);
    }

    /**
     * Find failed reports
     *
     * @return array<int, Report>
     * @throws DatabaseException
     */
    public function findFailed(int $limit = 10): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT * FROM report WHERE error = 1 ORDER BY start DESC LIMIT ?',
            [$limit]
        );

        return array_map(fn(array $row) => Report::fromDatabase($row), $rows);
    }

    /**
     * Create new report
     *
     * @throws DatabaseException
     */
    public function create(int $serverId, string $type): int
    {
        $this->connection->executeUpdate(
            'INSERT INTO report (server_id, type, start) VALUES (?, ?, NOW())',
            [$serverId, $type]
        );

        return $this->connection->getLastInsertId();
    }

    /**
     * Update report status
     *
     * @throws DatabaseException
     */
    public function updateStatus(
        int $id,
        ?string $currentPosition = null,
        ?int $originalSize = null,
        ?int $compressedSize = null,
        ?int $deduplicatedSize = null,
        ?float $duration = null,
        ?int $archiveCount = null,
        ?int $filesCount = null
    ): void {
        $updates = [];
        $params = [];

        if ($currentPosition !== null) {
            $updates[] = 'curpos = ?';
            $params[] = $currentPosition;
        }
        if ($originalSize !== null) {
            $updates[] = 'osize = ?';
            $params[] = $originalSize;
        }
        if ($compressedSize !== null) {
            $updates[] = 'csize = ?';
            $params[] = $compressedSize;
        }
        if ($deduplicatedSize !== null) {
            $updates[] = 'dsize = ?';
            $params[] = $deduplicatedSize;
        }
        if ($duration !== null) {
            $updates[] = 'dur = ?';
            $params[] = $duration;
        }
        if ($archiveCount !== null) {
            $updates[] = 'nb_archive = ?';
            $params[] = $archiveCount;
        }
        if ($filesCount !== null) {
            $updates[] = 'nfiles = ?';
            $params[] = $filesCount;
        }

        if (empty($updates)) {
            return;
        }

        $params[] = $id;
        $sql = 'UPDATE report SET ' . implode(', ', $updates) . ' WHERE id = ?';

        $this->connection->executeUpdate($sql, $params);
    }

    /**
     * Complete report
     *
     * @throws DatabaseException
     */
    public function complete(int $id, bool $hasError = false, ?string $errorLog = null): void
    {
        $this->connection->executeUpdate(
            'UPDATE report SET end = NOW(), error = ?, log = ?, curpos = NULL WHERE id = ?',
            [$hasError ? 1 : 0, $errorLog, $id]
        );
    }

    /**
     * Delete old reports
     *
     * @throws DatabaseException
     */
    public function deleteOlderThan(DateTimeImmutable $date): int
    {
        return $this->connection->executeUpdate(
            'DELETE FROM report WHERE start < ?',
            [$date->format('Y-m-d H:i:s')]
        );
    }
}
