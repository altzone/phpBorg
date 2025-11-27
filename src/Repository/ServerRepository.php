<?php

declare(strict_types=1);

namespace PhpBorg\Repository;

use PhpBorg\Database\Connection;
use PhpBorg\Entity\Server;
use PhpBorg\Exception\DatabaseException;

/**
 * Repository for Server entities
 */
final class ServerRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * Find server by ID
     *
     * @throws DatabaseException
     */
    public function findById(int $id): ?Server
    {
        $row = $this->connection->fetchOne(
            'SELECT * FROM servers WHERE id = ?',
            [$id]
        );

        return $row ? Server::fromDatabase($row) : null;
    }

    /**
     * Find server by name
     *
     * @throws DatabaseException
     */
    public function findByName(string $name): ?Server
    {
        $row = $this->connection->fetchOne(
            'SELECT * FROM servers WHERE name = ?',
            [$name]
        );

        return $row ? Server::fromDatabase($row) : null;
    }

    /**
     * Find all active servers
     *
     * @return array<int, Server>
     * @throws DatabaseException
     */
    public function findAllActive(): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT * FROM servers WHERE active = 1 ORDER BY name'
        );

        return array_map(fn(array $row) => Server::fromDatabase($row), $rows);
    }

    /**
     * Find all servers
     *
     * @return array<int, Server>
     * @throws DatabaseException
     */
    public function findAll(): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT * FROM servers ORDER BY name'
        );

        return array_map(fn(array $row) => Server::fromDatabase($row), $rows);
    }

    /**
     * Save new server
     *
     * @throws DatabaseException
     */
    public function create(
        string $name,
        string $host,
        int $port,
        string $backupType,
        string $sshPublicKey,
        bool $active = true
    ): int {
        $this->connection->executeUpdate(
            'INSERT INTO servers (name, host, port, backuptype, ssh_pub_key, active)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$name, $host, $port, $backupType, $sshPublicKey, $active ? 1 : 0]
        );

        return $this->connection->getLastInsertId();
    }

    /**
     * Update server
     *
     * @throws DatabaseException
     */
    public function update(Server $server): void
    {
        $this->connection->executeUpdate(
            'UPDATE servers SET name = ?, host = ?, port = ?, backuptype = ?,
             ssh_pub_key = ?, active = ? WHERE id = ?',
            [
                $server->name,
                $server->host,
                $server->port,
                $server->backupType,
                $server->sshPublicKey,
                $server->active ? 1 : 0,
                $server->id
            ]
        );
    }

    /**
     * Delete server by ID
     *
     * @throws DatabaseException
     */
    public function delete(int $id): void
    {
        $this->connection->executeUpdate(
            'DELETE FROM servers WHERE id = ?',
            [$id]
        );
    }

    /**
     * Set server active status
     *
     * @throws DatabaseException
     */
    public function setActive(int $id, bool $active): void
    {
        $this->connection->executeUpdate(
            'UPDATE servers SET active = ? WHERE id = ?',
            [$active ? 1 : 0, $id]
        );
    }

    /**
     * Update SSH keys configuration for server
     *
     * @throws DatabaseException
     */
    public function updateSSHKeys(
        int $id,
        string $sshPublicKey,
        string $sshPrivateKeyPath,
        bool $keysDeployed,
        string $backupServerUser
    ): void {
        $this->connection->executeUpdate(
            'UPDATE servers SET
             ssh_pub_key = ?,
             ssh_private_key_path = ?,
             ssh_keys_deployed = ?,
             backup_server_user = ?
             WHERE id = ?',
            [
                $sshPublicKey,
                $sshPrivateKeyPath,
                $keysDeployed ? 1 : 0,
                $backupServerUser,
                $id
            ]
        );
    }

    /**
     * Create server with agent (new flow)
     *
     * @param array $data Server and agent data
     * @return int Server ID
     * @throws DatabaseException
     */
    public function createWithAgent(array $data): int
    {
        $this->connection->executeUpdate(
            'INSERT INTO servers (
                name, host, port, backuptype, ssh_pub_key, active,
                agent_uuid, agent_status, agent_last_heartbeat, agent_version,
                connection_mode, capabilities_data
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $data['name'],
                $data['host'],
                $data['port'] ?? 22,
                $data['backuptype'] ?? 'agent',
                $data['ssh_pub_key'] ?? '',
                $data['active'] ?? 1,
                $data['agent_uuid'],
                $data['agent_status'] ?? 'pending',
                $data['agent_last_heartbeat'] ?? null,
                $data['agent_version'] ?? null,
                $data['connection_mode'] ?? 'agent',
                $data['capabilities_data'] ?? null,
            ]
        );

        return $this->connection->getLastInsertId();
    }

    /**
     * Find server by agent UUID
     *
     * @throws DatabaseException
     */
    public function findByAgentUuid(string $uuid): ?Server
    {
        $row = $this->connection->fetchOne(
            'SELECT * FROM servers WHERE agent_uuid = ?',
            [$uuid]
        );

        return $row ? Server::fromDatabase($row) : null;
    }

    /**
     * Find all servers with agents
     *
     * @return array<int, Server>
     * @throws DatabaseException
     */
    public function findAllWithAgents(): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT * FROM servers WHERE connection_mode = ? ORDER BY name',
            ['agent']
        );

        return array_map(fn(array $row) => Server::fromDatabase($row), $rows);
    }

    /**
     * Update agent status
     *
     * @throws DatabaseException
     */
    public function updateAgentStatus(
        int $id,
        string $status,
        ?string $lastHeartbeat = null,
        ?string $version = null
    ): void {
        $params = [$status];
        $sql = 'UPDATE servers SET agent_status = ?';

        if ($lastHeartbeat !== null) {
            $sql .= ', agent_last_heartbeat = ?';
            $params[] = $lastHeartbeat;
        }

        if ($version !== null) {
            $sql .= ', agent_version = ?';
            $params[] = $version;
        }

        $sql .= ' WHERE id = ?';
        $params[] = $id;

        $this->connection->executeUpdate($sql, $params);
    }

    /**
     * Update agent heartbeat
     *
     * @throws DatabaseException
     */
    public function updateAgentHeartbeat(string $agentUuid, ?string $version = null): void
    {
        $now = date('Y-m-d H:i:s');

        if ($version !== null) {
            $this->connection->executeUpdate(
                'UPDATE servers SET agent_last_heartbeat = ?, agent_version = ?, agent_status = ? WHERE agent_uuid = ?',
                [$now, $version, 'active', $agentUuid]
            );
        } else {
            $this->connection->executeUpdate(
                'UPDATE servers SET agent_last_heartbeat = ?, agent_status = ? WHERE agent_uuid = ?',
                [$now, 'active', $agentUuid]
            );
        }
    }

    /**
     * Set agent install token
     *
     * @throws DatabaseException
     */
    public function setAgentInstallToken(int $id, string $token, \DateTimeInterface $expires): void
    {
        $this->connection->executeUpdate(
            'UPDATE servers SET agent_install_token = ?, agent_install_token_expires = ?, agent_status = ? WHERE id = ?',
            [$token, $expires->format('Y-m-d H:i:s'), 'pending', $id]
        );
    }

    /**
     * Find server by agent install token
     *
     * @throws DatabaseException
     */
    public function findByAgentInstallToken(string $token): ?Server
    {
        $row = $this->connection->fetchOne(
            'SELECT * FROM servers WHERE agent_install_token = ? AND agent_install_token_expires > NOW()',
            [$token]
        );

        return $row ? Server::fromDatabase($row) : null;
    }

    /**
     * Clear agent install token
     *
     * @throws DatabaseException
     */
    public function clearAgentInstallToken(int $id): void
    {
        $this->connection->executeUpdate(
            'UPDATE servers SET agent_install_token = NULL, agent_install_token_expires = NULL WHERE id = ?',
            [$id]
        );
    }

    /**
     * Get servers with inactive agents (no heartbeat in last X minutes)
     *
     * @param int $minutes Minutes threshold
     * @return array<int, Server>
     * @throws DatabaseException
     */
    public function findInactiveAgents(int $minutes = 5): array
    {
        $threshold = date('Y-m-d H:i:s', strtotime("-{$minutes} minutes"));

        $rows = $this->connection->fetchAll(
            'SELECT * FROM servers
             WHERE connection_mode = ?
             AND agent_status = ?
             AND (agent_last_heartbeat IS NULL OR agent_last_heartbeat < ?)
             ORDER BY name',
            ['agent', 'active', $threshold]
        );

        return array_map(fn(array $row) => Server::fromDatabase($row), $rows);
    }

    /**
     * Mark inactive agents
     *
     * @param int $minutes Minutes threshold
     * @throws DatabaseException
     */
    public function markInactiveAgents(int $minutes = 5): int
    {
        $threshold = date('Y-m-d H:i:s', strtotime("-{$minutes} minutes"));

        return $this->connection->executeUpdate(
            'UPDATE servers SET agent_status = ?
             WHERE connection_mode = ?
             AND agent_status = ?
             AND (agent_last_heartbeat IS NULL OR agent_last_heartbeat < ?)',
            ['inactive', 'agent', 'active', $threshold]
        );
    }
}
