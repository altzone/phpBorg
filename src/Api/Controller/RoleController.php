<?php

declare(strict_types=1);

namespace PhpBorg\Api\Controller;

use PhpBorg\Application;
use PhpBorg\Repository\RolePermissionRepository;
use PhpBorg\Exception\PhpBorgException;

/**
 * Role and permission management API controller
 */
class RoleController extends BaseController
{
    private readonly RolePermissionRepository $rolePermissionRepository;

    public function __construct(Application $app)
    {
        $this->rolePermissionRepository = new RolePermissionRepository($app->getConnection());
    }

    /**
     * GET /api/roles
     * List all roles with their permissions
     */
    public function list(): void
    {
        try {
            // Only ROLE_ADMIN can view roles
            $currentUser = $_SERVER['USER'] ?? null;
            if (!$currentUser || !in_array('ROLE_ADMIN', $currentUser->roles)) {
                $this->error('Admin role required', 403, 'FORBIDDEN');
                return;
            }

            $permissionsByRole = $this->rolePermissionRepository->getAllGroupedByRole();

            $roles = [];
            foreach ($permissionsByRole as $role => $permissions) {
                $roles[] = [
                    'name' => $role,
                    'permissions' => $permissions,
                    'total_permissions' => count($permissions),
                    'enabled_permissions' => count(array_filter($permissions)),
                ];
            }

            $this->success([
                'roles' => $roles,
                'total' => count($roles),
            ]);
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'ROLES_LIST_ERROR');
        }
    }

    /**
     * GET /api/roles/:role
     * Get permissions for a specific role
     */
    public function show(): void
    {
        try {
            // Only ROLE_ADMIN can view roles
            $currentUser = $_SERVER['USER'] ?? null;
            if (!$currentUser || !in_array('ROLE_ADMIN', $currentUser->roles)) {
                $this->error('Admin role required', 403, 'FORBIDDEN');
                return;
            }

            $role = $_SERVER['ROUTE_PARAMS']['role'] ?? '';

            if (empty($role)) {
                $this->error('Role name is required', 400, 'INVALID_ROLE');
                return;
            }

            $permissions = $this->rolePermissionRepository->findByRole($role);

            if (empty($permissions)) {
                $this->error('Role not found', 404, 'ROLE_NOT_FOUND');
                return;
            }

            // Convert to simple key-value array
            $permissionsArray = [];
            foreach ($permissions as $permission) {
                $permissionsArray[$permission->permission] = $permission->enabled;
            }

            $this->success([
                'role' => $role,
                'permissions' => $permissionsArray,
                'total_permissions' => count($permissionsArray),
                'enabled_permissions' => count(array_filter($permissionsArray)),
            ]);
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'ROLE_SHOW_ERROR');
        }
    }

    /**
     * PUT /api/roles/:role/permissions
     * Update permissions for a role
     */
    public function updatePermissions(): void
    {
        try {
            // Only ROLE_ADMIN can update roles
            $currentUser = $_SERVER['USER'] ?? null;
            if (!$currentUser || !in_array('ROLE_ADMIN', $currentUser->roles)) {
                $this->error('Admin role required', 403, 'FORBIDDEN');
                return;
            }

            $role = $_SERVER['ROUTE_PARAMS']['role'] ?? '';

            if (empty($role)) {
                $this->error('Role name is required', 400, 'INVALID_ROLE');
                return;
            }

            // Verify role exists
            $existingPermissions = $this->rolePermissionRepository->findByRole($role);
            if (empty($existingPermissions)) {
                $this->error('Role not found', 404, 'ROLE_NOT_FOUND');
                return;
            }

            $data = $this->getJsonBody();

            if (!isset($data['permissions']) || !is_array($data['permissions'])) {
                $this->error('Permissions object is required', 400, 'INVALID_PERMISSIONS');
                return;
            }

            // Prevent ROLE_ADMIN from losing all permissions
            if ($role === 'ROLE_ADMIN') {
                $enabledCount = count(array_filter($data['permissions']));
                if ($enabledCount === 0) {
                    $this->error('ROLE_ADMIN must have at least one permission enabled', 400, 'ADMIN_NO_PERMISSIONS');
                    return;
                }
            }

            // Update permissions
            $this->rolePermissionRepository->updateRolePermissions($role, $data['permissions']);

            // Get updated permissions
            $permissions = $this->rolePermissionRepository->findByRole($role);
            $permissionsArray = [];
            foreach ($permissions as $permission) {
                $permissionsArray[$permission->permission] = $permission->enabled;
            }

            $this->success([
                'role' => $role,
                'permissions' => $permissionsArray,
            ], 'Permissions updated successfully');
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'ROLE_UPDATE_ERROR');
        }
    }

    /**
     * GET /api/permissions
     * Get all available permission keys
     */
    public function listPermissions(): void
    {
        try {
            // Only ROLE_ADMIN can view permissions
            $currentUser = $_SERVER['USER'] ?? null;
            if (!$currentUser || !in_array('ROLE_ADMIN', $currentUser->roles)) {
                $this->error('Admin role required', 403, 'FORBIDDEN');
                return;
            }

            $permissionKeys = $this->rolePermissionRepository->getAllPermissionKeys();

            // Group permissions by module
            $grouped = [];
            foreach ($permissionKeys as $key) {
                [$module] = explode('.', $key, 2);
                if (!isset($grouped[$module])) {
                    $grouped[$module] = [];
                }
                $grouped[$module][] = $key;
            }

            $this->success([
                'permissions' => $permissionKeys,
                'grouped' => $grouped,
                'total' => count($permissionKeys),
            ]);
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'PERMISSIONS_LIST_ERROR');
        }
    }
}
