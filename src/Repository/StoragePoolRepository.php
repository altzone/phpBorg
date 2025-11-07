<?php

declare(strict_types=1);

namespace PhpBorg\Repository;

use PhpBorg\Database\Connection;
use PhpBorg\Entity\StoragePool;
use PhpBorg\Exception\DatabaseException;

/**
 * Repository for StoragePool entities
 */
final class StoragePoolRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * Find storage pool by ID
     *
     * @throws DatabaseException
     */
    public function findById(int $id): ?StoragePool
    {
        $row = $this->connection->fetchOne(
            'SELECT * FROM storage_pools WHERE id = ?',
            [$id]
        );

        return $row ? StoragePool::fromDatabase($row) : null;
    }

    /**
     * Find all storage pools
     *
     * @return array<int, StoragePool>
     * @throws DatabaseException
     */
    public function findAll(): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT * FROM storage_pools ORDER BY default_pool DESC, name'
        );

        return array_map(fn(array $row) => StoragePool::fromDatabase($row), $rows);
    }

    /**
     * Find active storage pools
     *
     * @return array<int, StoragePool>
     * @throws DatabaseException
     */
    public function findActive(): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT * FROM storage_pools WHERE active = 1 ORDER BY default_pool DESC, name'
        );

        return array_map(fn(array $row) => StoragePool::fromDatabase($row), $rows);
    }

    /**
     * Get default storage pool
     *
     * @throws DatabaseException
     */
    public function getDefault(): ?StoragePool
    {
        $row = $this->connection->fetchOne(
            'SELECT * FROM storage_pools WHERE default_pool = 1 AND active = 1 LIMIT 1'
        );

        return $row ? StoragePool::fromDatabase($row) : null;
    }

    /**
     * Create new storage pool
     *
     * @throws DatabaseException
     */
    public function create(
        string $name,
        string $path,
        ?string $description = null,
        ?int $capacityTotal = null,
        bool $active = true,
        bool $defaultPool = false
    ): int {
        // If setting as default, unset other default pools
        if ($defaultPool) {
            $this->connection->executeUpdate(
                'UPDATE storage_pools SET default_pool = 0'
            );
        }

        $this->connection->executeUpdate(
            'INSERT INTO storage_pools (name, path, description, capacity_total, capacity_used, active, default_pool, created_at)
             VALUES (?, ?, ?, ?, 0, ?, ?, NOW())',
            [$name, $path, $description, $capacityTotal, $active ? 1 : 0, $defaultPool ? 1 : 0]
        );

        return $this->connection->getLastInsertId();
    }

    /**
     * Update storage pool
     *
     * @throws DatabaseException
     */
    public function update(
        int $id,
        string $name,
        string $path,
        ?string $description,
        ?int $capacityTotal,
        bool $active,
        bool $defaultPool
    ): void {
        // If setting as default, unset other default pools
        if ($defaultPool) {
            $this->connection->executeUpdate(
                'UPDATE storage_pools SET default_pool = 0 WHERE id != ?',
                [$id]
            );
        }

        $this->connection->executeUpdate(
            'UPDATE storage_pools
             SET name = ?, path = ?, description = ?, capacity_total = ?, active = ?, default_pool = ?, updated_at = NOW()
             WHERE id = ?',
            [$name, $path, $description, $capacityTotal, $active ? 1 : 0, $defaultPool ? 1 : 0, $id]
        );
    }

    /**
     * Update storage pool usage
     *
     * @throws DatabaseException
     */
    public function updateUsage(int $id, int $capacityUsed): void
    {
        $this->connection->executeUpdate(
            'UPDATE storage_pools SET capacity_used = ?, updated_at = NOW() WHERE id = ?',
            [$capacityUsed, $id]
        );
    }

    /**
     * Delete storage pool
     *
     * @throws DatabaseException
     */
    public function delete(int $id): void
    {
        $this->connection->executeUpdate(
            'DELETE FROM storage_pools WHERE id = ?',
            [$id]
        );
    }

    /**
     * Check if pool is in use (has repositories)
     *
     * @throws DatabaseException
     */
    public function isInUse(int $id): bool
    {
        $row = $this->connection->fetchOne(
            'SELECT COUNT(*) as count FROM repository WHERE storage_pool_id = ?',
            [$id]
        );

        return (int)($row['count'] ?? 0) > 0;
    }

    /**
     * Get number of repositories using this pool
     *
     * @throws DatabaseException
     */
    public function getRepositoryCount(int $id): int
    {
        $row = $this->connection->fetchOne(
            'SELECT COUNT(*) as count FROM repository WHERE storage_pool_id = ?',
            [$id]
        );

        return (int)($row['count'] ?? 0);
    }
}
