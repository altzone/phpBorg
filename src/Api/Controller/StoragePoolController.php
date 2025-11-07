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

            // Analyze filesystem automatically
            $analysis = $this->analyzeFilesystem($data['path']);

            // Create storage pool with analysis data
            $poolId = $this->storagePoolRepository->create(
                name: $data['name'],
                path: $data['path'],
                description: $data['description'] ?? null,
                capacityTotal: isset($data['capacity_total']) ? (int)$data['capacity_total'] : ($analysis['total_bytes'] ?? null),
                active: $data['active'] ?? true,
                defaultPool: $data['default_pool'] ?? false,
                filesystemType: $analysis['filesystem'] ?? null,
                storageType: $analysis['type'] ?? null,
                mountPoint: $analysis['mount_point'] ?? null,
                availableBytes: $analysis['available_bytes'] ?? null,
                usagePercent: $analysis['usage_percent'] ?? null
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

            // Analyze filesystem (for new path or to refresh data)
            $pathToAnalyze = $data['path'] ?? $pool->path;
            $analysis = $this->analyzeFilesystem($pathToAnalyze);

            // Update storage pool with analysis data
            $this->storagePoolRepository->update(
                id: $poolId,
                name: $data['name'] ?? $pool->name,
                path: $data['path'] ?? $pool->path,
                description: $data['description'] ?? $pool->description,
                capacityTotal: isset($data['capacity_total']) ? (int)$data['capacity_total'] : ($analysis['total_bytes'] ?? $pool->capacityTotal),
                active: isset($data['active']) ? (bool)$data['active'] : $pool->active,
                defaultPool: isset($data['default_pool']) ? (bool)$data['default_pool'] : $pool->defaultPool,
                filesystemType: $analysis['filesystem'] ?? $pool->filesystemType,
                storageType: $analysis['type'] ?? $pool->storageType,
                mountPoint: $analysis['mount_point'] ?? $pool->mountPoint,
                availableBytes: $analysis['available_bytes'] ?? $pool->availableBytes,
                usagePercent: $analysis['usage_percent'] ?? $pool->usagePercent
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

    /**
     * POST /api/storage-pools/analyze
     * Analyze a filesystem path
     */
    public function analyzePath(): void
    {
        try {
            $currentUser = $_SERVER['USER'] ?? null;
            if (!$currentUser) {
                $this->error('Authentication required', 401, 'UNAUTHORIZED');
                return;
            }

            $data = $this->getJsonBody();

            // Validate path
            if (empty($data['path'])) {
                $this->error('Path is required', 400, 'MISSING_PATH');
                return;
            }

            $path = $data['path'];

            // Check if path exists
            if (!file_exists($path)) {
                $this->error('Path does not exist', 400, 'PATH_NOT_FOUND');
                return;
            }

            if (!is_dir($path)) {
                $this->error('Path must be a directory', 400, 'NOT_A_DIRECTORY');
                return;
            }

            // Analyze the filesystem
            $analysisData = $this->analyzeFilesystem($path);

            $this->success(['analysis' => $analysisData], 'Filesystem analyzed successfully');
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'ANALYSIS_ERROR');
        } catch (\Exception $e) {
            $this->error('Failed to analyze path: ' . $e->getMessage(), 500, 'ANALYSIS_ERROR');
        }
    }

    /**
     * Analyze filesystem using system commands
     * Returns: type (nfs, local_disk, etc), filesystem, capacity, usage
     */
    private function analyzeFilesystem(string $path): array
    {
        $result = [
            'path' => $path,
            'type' => 'unknown',
            'filesystem' => null,
            'mount_point' => null,
            'total' => null,
            'used' => null,
            'available' => null,
            'usage_percent' => null,
            'total_bytes' => null,
            'used_bytes' => null,
            'available_bytes' => null,
        ];

        try {
            // Use df to get disk usage
            $dfCommand = sprintf('df -P -B1 %s 2>&1', escapeshellarg($path));
            exec($dfCommand, $dfOutput, $dfReturnCode);

            if ($dfReturnCode === 0 && count($dfOutput) >= 2) {
                // Parse df output (POSIX format with -P)
                // Filesystem 1-blocks Used Available Capacity Mounted on
                $parts = preg_split('/\s+/', $dfOutput[1]);

                if (count($parts) >= 6) {
                    $result['filesystem'] = $parts[0];
                    $result['total_bytes'] = (int)$parts[1];
                    $result['used_bytes'] = (int)$parts[2];
                    $result['available_bytes'] = (int)$parts[3];
                    $result['usage_percent'] = (int)rtrim($parts[4], '%');
                    $result['mount_point'] = $parts[5];

                    // Format human-readable sizes
                    $result['total'] = $this->formatBytes($result['total_bytes']);
                    $result['used'] = $this->formatBytes($result['used_bytes']);
                    $result['available'] = $this->formatBytes($result['available_bytes']);
                }
            }

            // Use findmnt to get filesystem type
            $findmntCommand = sprintf('findmnt -n -o FSTYPE,SOURCE -T %s 2>&1', escapeshellarg($path));
            exec($findmntCommand, $findmntOutput, $findmntReturnCode);

            if ($findmntReturnCode === 0 && !empty($findmntOutput)) {
                $parts = preg_split('/\s+/', $findmntOutput[0], 2);

                if (count($parts) >= 1) {
                    $fstype = $parts[0];
                    $source = $parts[1] ?? '';

                    // Determine storage type and set filesystem info
                    if (in_array($fstype, ['nfs', 'nfs4'])) {
                        $result['type'] = 'nfs';
                        $result['filesystem'] = $source; // NFS: show network path (e.g., "192.168.1.10:/exports")
                    } elseif (in_array($fstype, ['ext4', 'ext3', 'xfs', 'btrfs', 'zfs'])) {
                        $result['type'] = 'local_disk';
                        $result['filesystem'] = $fstype; // Local disk: show filesystem type (e.g., "ext4", "xfs")
                    } elseif (in_array($fstype, ['cifs', 'smb', 'smbfs'])) {
                        $result['type'] = 'smb';
                        $result['filesystem'] = $source; // SMB: show network path (e.g., "//server/share")
                    } else {
                        $result['type'] = $fstype;
                        $result['filesystem'] = $fstype; // Unknown: show filesystem type
                    }
                }
            }
        } catch (\Exception $e) {
            // If commands fail, return what we have
            error_log('Filesystem analysis failed: ' . $e->getMessage());
        }

        return $result;
    }

    /**
     * Format bytes to human-readable format
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }
}
