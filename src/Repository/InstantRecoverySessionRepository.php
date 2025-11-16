<?php

declare(strict_types=1);

namespace PhpBorg\Repository;

use PhpBorg\Database\Connection;
use PhpBorg\Entity\InstantRecoverySession;
use PhpBorg\Exception\DatabaseException;

/**
 * Repository for Instant Recovery Sessions
 */
final class InstantRecoverySessionRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * Find session by ID
     */
    public function findById(int $id): ?InstantRecoverySession
    {
        $row = $this->connection->fetchOne(
            'SELECT * FROM instant_recovery_sessions WHERE id = ?',
            [$id]
        );

        return $row ? InstantRecoverySession::fromDatabase($row) : null;
    }

    /**
     * Find session by archive ID
     */
    public function findByArchiveId(int $archiveId): ?InstantRecoverySession
    {
        $row = $this->connection->fetchOne(
            'SELECT * FROM instant_recovery_sessions WHERE archive_id = ? AND status IN (?, ?)',
            [$archiveId, 'starting', 'active']
        );

        return $row ? InstantRecoverySession::fromDatabase($row) : null;
    }

    /**
     * Find all active sessions
     *
     * @return array<int, InstantRecoverySession>
     */
    public function findActive(): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT * FROM instant_recovery_sessions WHERE status IN (?, ?) ORDER BY created_at DESC',
            ['starting', 'active']
        );

        return array_map(fn($row) => InstantRecoverySession::fromDatabase($row), $rows);
    }

    /**
     * Find all sessions (for admin)
     *
     * @return array<int, InstantRecoverySession>
     */
    public function findAll(int $limit = 100): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT * FROM instant_recovery_sessions ORDER BY created_at DESC LIMIT ?',
            [$limit]
        );

        return array_map(fn($row) => InstantRecoverySession::fromDatabase($row), $rows);
    }

    /**
     * Create new instant recovery session (Docker-based)
     */
    public function create(
        int $archiveId,
        int $serverId,
        string $dbType,
        string $deploymentLocation,
        string $borgMountPoint,
        int $dbPort
    ): int {
        $this->connection->executeUpdate(
            'INSERT INTO instant_recovery_sessions
             (archive_id, server_id, db_type, deployment_location, borg_mount_point, db_port, status)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $archiveId,
                $serverId,
                $dbType,
                $deploymentLocation,
                $borgMountPoint,
                $dbPort,
                'starting'
            ]
        );

        return $this->connection->getLastInsertId();
    }

    /**
     * Update temp data directory path
     */
    public function updateTempDataDir(int $id, string $tempDataDir): void
    {
        $this->connection->executeUpdate(
            'UPDATE instant_recovery_sessions SET temp_data_dir = ? WHERE id = ?',
            [$tempDataDir, $id]
        );
    }

    /**
     * Update session to active status
     */
    public function markActive(int $id, int $dbPid, ?string $dbSocket, string $connectionString): void
    {
        $this->connection->executeUpdate(
            'UPDATE instant_recovery_sessions
             SET status = ?, started_at = NOW(), db_pid = ?, db_socket = ?, connection_string = ?
             WHERE id = ?',
            ['active', $dbPid, $dbSocket, $connectionString, $id]
        );
    }

    /**
     * Update session to stopped status
     */
    public function markStopped(int $id): void
    {
        $this->connection->executeUpdate(
            'UPDATE instant_recovery_sessions
             SET status = ?, stopped_at = NOW()
             WHERE id = ?',
            ['stopped', $id]
        );
    }

    /**
     * Update session to failed status
     */
    public function markFailed(int $id, string $errorMessage): void
    {
        $this->connection->executeUpdate(
            'UPDATE instant_recovery_sessions
             SET status = ?, stopped_at = NOW(), error_message = ?
             WHERE id = ?',
            ['failed', $errorMessage, $id]
        );
    }

    /**
     * Delete session
     */
    public function delete(int $id): void
    {
        $this->connection->executeUpdate(
            'DELETE FROM instant_recovery_sessions WHERE id = ?',
            [$id]
        );
    }

    /**
     * Get connection for transactions
     */
    public function getConnection(): Connection
    {
        return $this->connection;
    }
}
