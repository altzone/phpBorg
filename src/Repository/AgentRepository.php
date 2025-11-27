<?php

declare(strict_types=1);

namespace PhpBorg\Repository;

use PhpBorg\Database\Connection;
use PhpBorg\Exception\DatabaseException;

/**
 * Repository for Agent entities
 */
final class AgentRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * Find agent by ID
     */
    public function findById(int $id): ?array
    {
        return $this->connection->fetchOne(
            'SELECT * FROM agents WHERE id = ?',
            [$id]
        );
    }

    /**
     * Find agent by UUID
     */
    public function findByUuid(string $uuid): ?array
    {
        return $this->connection->fetchOne(
            'SELECT * FROM agents WHERE uuid = ?',
            [$uuid]
        );
    }

    /**
     * Find agent by name
     */
    public function findByName(string $name): ?array
    {
        return $this->connection->fetchOne(
            'SELECT * FROM agents WHERE name = ?',
            [$name]
        );
    }

    /**
     * Find agent by certificate CN
     */
    public function findByCertificateCN(string $cn): ?array
    {
        return $this->connection->fetchOne(
            'SELECT * FROM agents WHERE certificate_cn = ?',
            [$cn]
        );
    }

    /**
     * Find all agents
     */
    public function findAll(): array
    {
        return $this->connection->fetchAll(
            'SELECT * FROM agents ORDER BY name'
        );
    }

    /**
     * Find all active agents
     */
    public function findActive(): array
    {
        return $this->connection->fetchAll(
            "SELECT * FROM agents WHERE status = 'active' ORDER BY name"
        );
    }

    /**
     * Find agents with expiring certificates
     */
    public function findWithExpiringCertificates(int $daysUntilExpiry = 30): array
    {
        return $this->connection->fetchAll(
            'SELECT * FROM agents
             WHERE certificate_expires_at IS NOT NULL
             AND certificate_expires_at < DATE_ADD(NOW(), INTERVAL ? DAY)
             AND status = ?
             ORDER BY certificate_expires_at',
            [$daysUntilExpiry, 'active']
        );
    }

    /**
     * Find agents that haven't sent heartbeat recently
     */
    public function findStaleAgents(int $secondsSinceHeartbeat = 300): array
    {
        return $this->connection->fetchAll(
            "SELECT * FROM agents
             WHERE status = 'active'
             AND (last_heartbeat IS NULL OR last_heartbeat < DATE_SUB(NOW(), INTERVAL ? SECOND))
             ORDER BY last_heartbeat",
            [$secondsSinceHeartbeat]
        );
    }

    /**
     * Create new agent
     */
    public function create(
        string $uuid,
        string $name,
        string $hostname,
        string $sshPublicKey,
        string $backupPath,
        bool $appendOnly = true,
        ?int $registeredBy = null
    ): int {
        $this->connection->executeUpdate(
            'INSERT INTO agents
             (uuid, name, hostname, ssh_public_key, backup_path, append_only,
              status, registered_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                $uuid,
                $name,
                $hostname,
                $sshPublicKey,
                $backupPath,
                $appendOnly ? 1 : 0,
                'pending',
                $registeredBy,
            ]
        );

        return $this->connection->getLastInsertId();
    }

    /**
     * Update agent status
     */
    public function updateStatus(int $id, string $status): void
    {
        $this->connection->executeUpdate(
            'UPDATE agents SET status = ?, updated_at = NOW() WHERE id = ?',
            [$status, $id]
        );
    }

    /**
     * Update heartbeat timestamp
     */
    public function updateHeartbeat(int $id, ?string $ipAddress = null, ?string $version = null): void
    {
        $sql = 'UPDATE agents SET last_heartbeat = NOW(), updated_at = NOW()';
        $params = [];

        if ($ipAddress !== null) {
            $sql .= ', ip_address = ?';
            $params[] = $ipAddress;
        }

        if ($version !== null) {
            $sql .= ', version = ?';
            $params[] = $version;
        }

        $sql .= ' WHERE id = ?';
        $params[] = $id;

        $this->connection->executeUpdate($sql, $params);
    }

    /**
     * Update certificate information
     */
    public function updateCertificate(
        int $id,
        string $certificateCN,
        \DateTimeInterface $expiresAt,
        string $fingerprint
    ): void {
        $this->connection->executeUpdate(
            'UPDATE agents SET
             certificate_cn = ?,
             certificate_expires_at = ?,
             certificate_fingerprint = ?,
             updated_at = NOW()
             WHERE id = ?',
            [
                $certificateCN,
                $expiresAt->format('Y-m-d H:i:s'),
                $fingerprint,
                $id,
            ]
        );
    }

    /**
     * Update capabilities
     */
    public function updateCapabilities(int $id, array $capabilities): void
    {
        $this->connection->executeUpdate(
            'UPDATE agents SET capabilities = ?, updated_at = NOW() WHERE id = ?',
            [json_encode($capabilities), $id]
        );
    }

    /**
     * Update OS info
     */
    public function updateOsInfo(int $id, string $osInfo): void
    {
        $this->connection->executeUpdate(
            'UPDATE agents SET os_info = ?, updated_at = NOW() WHERE id = ?',
            [$osInfo, $id]
        );
    }

    /**
     * Update append-only setting
     */
    public function updateAppendOnly(int $id, bool $appendOnly): void
    {
        $this->connection->executeUpdate(
            'UPDATE agents SET append_only = ?, updated_at = NOW() WHERE id = ?',
            [$appendOnly ? 1 : 0, $id]
        );
    }

    /**
     * Link agent to legacy server
     */
    public function linkToServer(int $agentId, int $serverId): void
    {
        $this->connection->executeUpdate(
            'UPDATE agents SET server_id = ?, updated_at = NOW() WHERE id = ?',
            [$serverId, $agentId]
        );
    }

    /**
     * Delete agent
     */
    public function delete(int $id): void
    {
        $this->connection->executeUpdate(
            'DELETE FROM agents WHERE id = ?',
            [$id]
        );
    }

    /**
     * Count agents by status
     */
    public function countByStatus(): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT status, COUNT(*) as count FROM agents GROUP BY status'
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['status']] = (int) $row['count'];
        }

        return $counts;
    }
}
