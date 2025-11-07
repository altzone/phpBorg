<?php

declare(strict_types=1);

namespace PhpBorg\Repository;

use PhpBorg\Database\Connection;
use PhpBorg\Entity\RolePermission;
use PhpBorg\Exception\DatabaseException;

/**
 * Repository for RolePermission entities
 */
final class RolePermissionRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * Find permissions by role
     *
     * @return array<int, RolePermission>
     * @throws DatabaseException
     */
    public function findByRole(string $role): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT * FROM role_permissions WHERE role = ? ORDER BY permission',
            [$role]
        );

        return array_map(fn(array $row) => RolePermission::fromDatabase($row), $rows);
    }

    /**
     * Get all permissions grouped by role
     *
     * @return array<string, array<string, bool>> Role => [permission => enabled]
     * @throws DatabaseException
     */
    public function getAllGroupedByRole(): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT * FROM role_permissions ORDER BY role, permission'
        );

        $grouped = [];
        foreach ($rows as $row) {
            $permission = RolePermission::fromDatabase($row);
            $grouped[$permission->role][$permission->permission] = $permission->enabled;
        }

        return $grouped;
    }

    /**
     * Get all unique permission keys
     *
     * @return array<int, string>
     * @throws DatabaseException
     */
    public function getAllPermissionKeys(): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT DISTINCT permission FROM role_permissions ORDER BY permission'
        );

        return array_map(fn(array $row) => $row['permission'], $rows);
    }

    /**
     * Get all unique roles
     *
     * @return array<int, string>
     * @throws DatabaseException
     */
    public function getAllRoles(): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT DISTINCT role FROM role_permissions ORDER BY role'
        );

        return array_map(fn(array $row) => $row['role'], $rows);
    }

    /**
     * Check if role has permission
     *
     * @throws DatabaseException
     */
    public function hasPermission(string $role, string $permission): bool
    {
        $row = $this->connection->fetchOne(
            'SELECT enabled FROM role_permissions WHERE role = ? AND permission = ?',
            [$role, $permission]
        );

        return isset($row['enabled']) && (bool)$row['enabled'];
    }

    /**
     * Update permission for role
     *
     * @throws DatabaseException
     */
    public function updatePermission(string $role, string $permission, bool $enabled): void
    {
        // Try to update first
        $affected = $this->connection->executeUpdate(
            'UPDATE role_permissions SET enabled = ? WHERE role = ? AND permission = ?',
            [$enabled ? 1 : 0, $role, $permission]
        );

        // If no rows affected, insert new
        if ($affected === 0) {
            $this->connection->executeUpdate(
                'INSERT INTO role_permissions (role, permission, enabled) VALUES (?, ?, ?)',
                [$role, $permission, $enabled ? 1 : 0]
            );
        }
    }

    /**
     * Update multiple permissions for a role
     *
     * @param array<string, bool> $permissions Permission => enabled
     * @throws DatabaseException
     */
    public function updateRolePermissions(string $role, array $permissions): void
    {
        foreach ($permissions as $permission => $enabled) {
            $this->updatePermission($role, $permission, $enabled);
        }
    }

    /**
     * Create permission for role
     *
     * @throws DatabaseException
     */
    public function create(string $role, string $permission, bool $enabled = true): int
    {
        $this->connection->executeUpdate(
            'INSERT INTO role_permissions (role, permission, enabled) VALUES (?, ?, ?)',
            [$role, $permission, $enabled ? 1 : 0]
        );

        return $this->connection->getLastInsertId();
    }

    /**
     * Delete permission
     *
     * @throws DatabaseException
     */
    public function delete(int $id): void
    {
        $this->connection->executeUpdate(
            'DELETE FROM role_permissions WHERE id = ?',
            [$id]
        );
    }

    /**
     * Delete all permissions for a role
     *
     * @throws DatabaseException
     */
    public function deleteByRole(string $role): void
    {
        $this->connection->executeUpdate(
            'DELETE FROM role_permissions WHERE role = ?',
            [$role]
        );
    }
}
