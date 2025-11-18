<?php

declare(strict_types=1);

namespace PhpBorg\Repository;

use PhpBorg\Entity\BackupSource;
use PhpBorg\Database\Connection;

/**
 * Repository for backup sources
 */
class BackupSourceRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * Find all backup sources
     * 
     * @return BackupSource[]
     */
    public function findAll(): array
    {
        $result = $this->connection->execute(
            'SELECT * FROM backup_sources ORDER BY name ASC'
        );

        $sources = [];
        foreach ($result as $row) {
            $sources[] = BackupSource::fromDatabase($row);
        }

        return $sources;
    }

    /**
     * Find active backup sources
     * 
     * @return BackupSource[]
     */
    public function findActive(): array
    {
        $result = $this->connection->execute(
            'SELECT * FROM backup_sources WHERE active = 1 ORDER BY name ASC'
        );

        $sources = [];
        foreach ($result as $row) {
            $sources[] = BackupSource::fromDatabase($row);
        }

        return $sources;
    }

    /**
     * Find backup source by ID
     */
    public function findById(int $id): ?BackupSource
    {
        $row = $this->connection->fetchOne(
            'SELECT * FROM backup_sources WHERE id = ?',
            [$id]
        );

        return $row ? BackupSource::fromDatabase($row) : null;
    }

    /**
     * Find backup sources by server ID
     * 
     * @return BackupSource[]
     */
    public function findByServerId(int $serverId): array
    {
        $result = $this->connection->execute(
            'SELECT * FROM backup_sources WHERE server_id = ? ORDER BY name ASC',
            [$serverId]
        );

        $sources = [];
        foreach ($result as $row) {
            $sources[] = BackupSource::fromDatabase($row);
        }

        return $sources;
    }

    /**
     * Find backup sources by type
     *
     * @return BackupSource[]
     */
    public function findByType(string $type): array
    {
        $result = $this->connection->execute(
            'SELECT * FROM backup_sources WHERE type = ? ORDER BY name ASC',
            [$type]
        );

        $sources = [];
        foreach ($result as $row) {
            $sources[] = BackupSource::fromDatabase($row);
        }

        return $sources;
    }

    /**
     * Find backup sources by server ID and type
     *
     * @return BackupSource[]
     */
    public function findByServerAndType(int $serverId, string $type): array
    {
        $result = $this->connection->execute(
            'SELECT * FROM backup_sources WHERE server_id = ? AND type = ? ORDER BY name ASC',
            [$serverId, $type]
        );

        $sources = [];
        foreach ($result as $row) {
            $sources[] = BackupSource::fromDatabase($row);
        }

        return $sources;
    }

    /**
     * Create a new backup source
     */
    public function create(array $data): BackupSource
    {
        $this->connection->executeUpdate(
            'INSERT INTO backup_sources (
                name, type, server_id, config, paths, exclude_patterns,
                pre_backup_script, post_backup_script, tags, active, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                $data['name'],
                $data['type'],
                $data['server_id'],
                json_encode($data['config'] ?? []),
                isset($data['paths']) ? json_encode($data['paths']) : null,
                isset($data['exclude_patterns']) ? json_encode($data['exclude_patterns']) : null,
                $data['pre_backup_script'] ?? null,
                $data['post_backup_script'] ?? null,
                isset($data['tags']) ? json_encode($data['tags']) : null,
                ($data['active'] ?? true) ? 1 : 0,  // Convert boolean to integer
            ]
        );

        $id = $this->connection->getLastInsertId();
        return $this->findById((int)$id);
    }

    /**
     * Update a backup source
     */
    public function update(int $id, array $data): BackupSource
    {
        $fields = [];
        $params = ['id' => $id];

        if (isset($data['name'])) {
            $fields[] = 'name = :name';
            $params['name'] = $data['name'];
        }

        if (isset($data['type'])) {
            $fields[] = 'type = :type';
            $params['type'] = $data['type'];
        }

        if (isset($data['server_id'])) {
            $fields[] = 'server_id = :server_id';
            $params['server_id'] = $data['server_id'];
        }

        if (isset($data['config'])) {
            $fields[] = 'config = :config';
            $params['config'] = json_encode($data['config']);
        }

        if (array_key_exists('paths', $data)) {
            $fields[] = 'paths = :paths';
            $params['paths'] = $data['paths'] !== null ? json_encode($data['paths']) : null;
        }

        if (array_key_exists('exclude_patterns', $data)) {
            $fields[] = 'exclude_patterns = :exclude_patterns';
            $params['exclude_patterns'] = $data['exclude_patterns'] !== null ? json_encode($data['exclude_patterns']) : null;
        }

        if (array_key_exists('pre_backup_script', $data)) {
            $fields[] = 'pre_backup_script = :pre_backup_script';
            $params['pre_backup_script'] = $data['pre_backup_script'];
        }

        if (array_key_exists('post_backup_script', $data)) {
            $fields[] = 'post_backup_script = :post_backup_script';
            $params['post_backup_script'] = $data['post_backup_script'];
        }

        if (array_key_exists('tags', $data)) {
            $fields[] = 'tags = :tags';
            $params['tags'] = $data['tags'] !== null ? json_encode($data['tags']) : null;
        }

        if (isset($data['active'])) {
            $fields[] = 'active = :active';
            $params['active'] = $data['active'];
        }

        if (!empty($fields)) {
            $fields[] = 'updated_at = NOW()';
            
            $this->connection->execute(
                'UPDATE backup_sources SET ' . implode(', ', $fields) . ' WHERE id = :id',
                $params
            );
        }

        return $this->findById($id);
    }

    /**
     * Delete a backup source
     */
    public function delete(int $id): bool
    {
        $this->connection->execute(
            'DELETE FROM backup_sources WHERE id = :id',
            ['id' => $id]
        );

        return $this->connection->affectedRows() > 0;
    }

    /**
     * Check if a backup source exists
     */
    public function exists(int $id): bool
    {
        $result = $this->connection->query(
            'SELECT COUNT(*) as count FROM backup_sources WHERE id = :id',
            ['id' => $id]
        );

        $row = $result->fetch();
        return $row && (int)$row['count'] > 0;
    }

    /**
     * Get backup source types with counts
     */
    public function getTypeStatistics(): array
    {
        $result = $this->connection->query(
            'SELECT type, COUNT(*) as count 
             FROM backup_sources 
             WHERE active = 1 
             GROUP BY type 
             ORDER BY count DESC'
        );

        $stats = [];
        foreach ($result as $row) {
            $stats[$row['type']] = (int)$row['count'];
        }

        return $stats;
    }

    /**
     * Search backup sources by name or tags
     * 
     * @return BackupSource[]
     */
    public function search(string $query): array
    {
        $result = $this->connection->query(
            'SELECT * FROM backup_sources 
             WHERE name LIKE :query 
                OR tags LIKE :query 
             ORDER BY name ASC',
            ['query' => '%' . $query . '%']
        );

        $sources = [];
        foreach ($result as $row) {
            $sources[] = BackupSource::fromDatabase($row);
        }

        return $sources;
    }
}