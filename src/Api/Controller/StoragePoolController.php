<?php

declare(strict_types=1);

namespace PhpBorg\Api\Controller;

use PhpBorg\Application;
use PhpBorg\Repository\StoragePoolRepository;
use PhpBorg\Exception\PhpBorgException;

/**
 * Storage pool management API controller
 */
class StoragePoolController extends BaseController
{
    private readonly StoragePoolRepository $storagePoolRepository;

    public function __construct(Application $app)
    {
        $this->storagePoolRepository = new StoragePoolRepository($app->getConnection());
    }

    /**
     * GET /api/storage-pools
     * List all storage pools
     */
    public function list(): void
    {
        try {
            $currentUser = $_SERVER['USER'] ?? null;
            if (!$currentUser) {
                $this->error('Authentication required', 401, 'UNAUTHORIZED');
                return;
            }

            $pools = $this->storagePoolRepository->findAll();

            // Add repository count to each pool
            $poolsWithStats = array_map(function ($pool) {
                $poolArray = $pool->toArray();
                $poolArray['repository_count'] = $this->storagePoolRepository->getRepositoryCount($pool->id);
                $poolArray['in_use'] = $this->storagePoolRepository->isInUse($pool->id);
                return $poolArray;
            }, $pools);

            $this->success([
                'storage_pools' => $poolsWithStats,
                'total' => count($pools),
            ]);
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'STORAGE_POOL_LIST_ERROR');
        }
    }

    /**
     * GET /api/storage-pools/:id
     * Get storage pool details
     */
    public function show(): void
    {
        try {
            $currentUser = $_SERVER['USER'] ?? null;
            if (!$currentUser) {
                $this->error('Authentication required', 401, 'UNAUTHORIZED');
                return;
            }

            $poolId = (int)($_SERVER['ROUTE_PARAMS']['id'] ?? 0);

            if ($poolId <= 0) {
                $this->error('Invalid storage pool ID', 400, 'INVALID_POOL_ID');
                return;
            }

            $pool = $this->storagePoolRepository->findById($poolId);

            if (!$pool) {
                $this->error('Storage pool not found', 404, 'POOL_NOT_FOUND');
                return;
            }

            $poolArray = $pool->toArray();
            $poolArray['repository_count'] = $this->storagePoolRepository->getRepositoryCount($pool->id);
            $poolArray['in_use'] = $this->storagePoolRepository->isInUse($pool->id);

            $this->success(['storage_pool' => $poolArray]);
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'STORAGE_POOL_SHOW_ERROR');
        }
    }

    /**
     * POST /api/storage-pools
     * Create new storage pool
     */
    public function create(): void
    {
        try {
            // Only ROLE_ADMIN can create storage pools
            $currentUser = $_SERVER['USER'] ?? null;
            if (!$currentUser || !in_array('ROLE_ADMIN', $currentUser->roles)) {
                $this->error('Admin role required', 403, 'FORBIDDEN');
                return;
            }

            $data = $this->getJsonBody();

            // Validate required fields
            $this->validateRequired($data, ['name', 'path']);

            // Validate name
            if (strlen($data['name']) < 3 || strlen($data['name']) > 100) {
                $this->error('Name must be between 3 and 100 characters', 400, 'INVALID_NAME');
                return;
            }

            // Validate path
            if (empty($data['path']) || strlen($data['path']) > 500) {
                $this->error('Invalid path', 400, 'INVALID_PATH');
                return;
            }

            // Create storage pool
            $poolId = $this->storagePoolRepository->create(
                name: $data['name'],
                path: $data['path'],
                description: $data['description'] ?? null,
                capacityTotal: isset($data['capacity_total']) ? (int)$data['capacity_total'] : null,
                active: $data['active'] ?? true,
                defaultPool: $data['default_pool'] ?? false
            );

            // Get created pool
            $pool = $this->storagePoolRepository->findById($poolId);
            $poolArray = $pool->toArray();
            $poolArray['repository_count'] = 0;
            $poolArray['in_use'] = false;

            $this->success(
                ['storage_pool' => $poolArray],
                'Storage pool created successfully',
                201
            );
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'STORAGE_POOL_CREATE_ERROR');
        }
    }

    /**
     * PUT /api/storage-pools/:id
     * Update storage pool
     */
    public function update(): void
    {
        try {
            // Only ROLE_ADMIN can update storage pools
            $currentUser = $_SERVER['USER'] ?? null;
            if (!$currentUser || !in_array('ROLE_ADMIN', $currentUser->roles)) {
                $this->error('Admin role required', 403, 'FORBIDDEN');
                return;
            }

            $poolId = (int)($_SERVER['ROUTE_PARAMS']['id'] ?? 0);

            if ($poolId <= 0) {
                $this->error('Invalid storage pool ID', 400, 'INVALID_POOL_ID');
                return;
            }

            // Check pool exists
            $pool = $this->storagePoolRepository->findById($poolId);
            if (!$pool) {
                $this->error('Storage pool not found', 404, 'POOL_NOT_FOUND');
                return;
            }

            $data = $this->getJsonBody();

            // Validate name if provided
            if (isset($data['name'])) {
                if (strlen($data['name']) < 3 || strlen($data['name']) > 100) {
                    $this->error('Name must be between 3 and 100 characters', 400, 'INVALID_NAME');
                    return;
                }
            }

            // Validate path if provided
            if (isset($data['path'])) {
                if (empty($data['path']) || strlen($data['path']) > 500) {
                    $this->error('Invalid path', 400, 'INVALID_PATH');
                    return;
                }
            }

            // Update storage pool
            $this->storagePoolRepository->update(
                id: $poolId,
                name: $data['name'] ?? $pool->name,
                path: $data['path'] ?? $pool->path,
                description: $data['description'] ?? $pool->description,
                capacityTotal: isset($data['capacity_total']) ? (int)$data['capacity_total'] : $pool->capacityTotal,
                active: isset($data['active']) ? (bool)$data['active'] : $pool->active,
                defaultPool: isset($data['default_pool']) ? (bool)$data['default_pool'] : $pool->defaultPool
            );

            // Get updated pool
            $pool = $this->storagePoolRepository->findById($poolId);
            $poolArray = $pool->toArray();
            $poolArray['repository_count'] = $this->storagePoolRepository->getRepositoryCount($pool->id);
            $poolArray['in_use'] = $this->storagePoolRepository->isInUse($pool->id);

            $this->success(['storage_pool' => $poolArray], 'Storage pool updated successfully');
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'STORAGE_POOL_UPDATE_ERROR');
        }
    }

    /**
     * DELETE /api/storage-pools/:id
     * Delete storage pool
     */
    public function delete(): void
    {
        try {
            // Only ROLE_ADMIN can delete storage pools
            $currentUser = $_SERVER['USER'] ?? null;
            if (!$currentUser || !in_array('ROLE_ADMIN', $currentUser->roles)) {
                $this->error('Admin role required', 403, 'FORBIDDEN');
                return;
            }

            $poolId = (int)($_SERVER['ROUTE_PARAMS']['id'] ?? 0);

            if ($poolId <= 0) {
                $this->error('Invalid storage pool ID', 400, 'INVALID_POOL_ID');
                return;
            }

            // Check pool exists
            $pool = $this->storagePoolRepository->findById($poolId);
            if (!$pool) {
                $this->error('Storage pool not found', 404, 'POOL_NOT_FOUND');
                return;
            }

            // Check if pool is in use
            if ($this->storagePoolRepository->isInUse($poolId)) {
                $count = $this->storagePoolRepository->getRepositoryCount($poolId);
                $this->error(
                    "Cannot delete storage pool that is in use by $count repository(ies)",
                    400,
                    'POOL_IN_USE'
                );
                return;
            }

            // Prevent deleting the last active pool
            $activePools = $this->storagePoolRepository->findActive();
            if (count($activePools) <= 1 && $pool->active) {
                $this->error('Cannot delete the last active storage pool', 400, 'LAST_ACTIVE_POOL');
                return;
            }

            // Delete pool
            $this->storagePoolRepository->delete($poolId);

            $this->success(['id' => $poolId], 'Storage pool deleted successfully');
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'STORAGE_POOL_DELETE_ERROR');
        }
    }
}
