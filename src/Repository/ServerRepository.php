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
}
