<?php

namespace PhpBorg\Api\Controller;

use PhpBorg\Api\Core\Controller;
use PhpBorg\Repository\StoragePoolRepository;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class StoragePoolController extends Controller
{
    private StoragePoolRepository $storagePoolRepository;

    public function __construct()
    {
        parent::__construct();
        $this->storagePoolRepository = new StoragePoolRepository($this->db);
    }

    /**
     * List all storage pools
     * GET /api/storage-pools
     */
    public function list(): void
    {
        try {
            $pools = $this->storagePoolRepository->findAll();

            // Enrich with repository count and usage info
            foreach ($pools as &$pool) {
                $pool['repository_count'] = $this->storagePoolRepository->getRepositoryCount($pool['id']);

                // Calculate usage percentage
                if ($pool['capacity_total'] && $pool['capacity_total'] > 0) {
                    $pool['usage_percentage'] = round(($pool['capacity_used'] / $pool['capacity_total']) * 100, 2);
                } else {
                    $pool['usage_percentage'] = null;
                }
            }

            $this->jsonResponse(['success' => true, 'data' => $pools]);
        } catch (\Exception $e) {
            $this->jsonResponse(['error' => 'Failed to fetch storage pools: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Get single storage pool
     * GET /api/storage-pools/:id
     */
    public function show(): void
    {
        try {
            $id = (int)$this->getRouteParam('id');
            $pool = $this->storagePoolRepository->findById($id);

            if (!$pool) {
                $this->jsonResponse(['error' => 'Storage pool not found'], 404);
                return;
            }

            $pool['repository_count'] = $this->storagePoolRepository->getRepositoryCount($id);

            if ($pool['capacity_total'] && $pool['capacity_total'] > 0) {
                $pool['usage_percentage'] = round(($pool['capacity_used'] / $pool['capacity_total']) * 100, 2);
            }

            $this->jsonResponse(['success' => true, 'data' => $pool]);
        } catch (\Exception $e) {
            $this->jsonResponse(['error' => 'Failed to fetch storage pool: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Analyze a filesystem path
     * POST /api/storage-pools/analyze
     * Body: { "path": "/backup/pool1" }
     */
    public function analyzePath(): void
    {
        try {
            $data = $this->getJsonInput();
            $path = $data['path'] ?? null;

            if (!$path) {
                $this->jsonResponse(['error' => 'Path is required'], 400);
                return;
            }

            // Check if path exists
            if (!file_exists($path)) {
                $this->jsonResponse(['error' => 'Path does not exist'], 400);
                return;
            }

            if (!is_dir($path)) {
                $this->jsonResponse(['error' => 'Path is not a directory'], 400);
                return;
            }

            $analysisData = $this->analyzeFilesystem($path);
            $this->jsonResponse(['success' => true, 'data' => $analysisData]);

        } catch (\Exception $e) {
            $this->jsonResponse(['error' => 'Failed to analyze path: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Create new storage pool
     * POST /api/storage-pools
     */
    public function create(): void
    {
        try {
            $data = $this->getJsonInput();

            // Validation
            if (empty($data['name'])) {
                $this->jsonResponse(['error' => 'Name is required'], 400);
                return;
            }

            if (empty($data['path'])) {
                $this->jsonResponse(['error' => 'Path is required'], 400);
                return;
            }

            // Check if path exists
            if (!file_exists($data['path'])) {
                $this->jsonResponse(['error' => 'Path does not exist'], 400);
                return;
            }

            if (!is_dir($data['path'])) {
                $this->jsonResponse(['error' => 'Path must be a directory'], 400);
                return;
            }

            // Check if path is writable
            if (!is_writable($data['path'])) {
                $this->jsonResponse(['error' => 'Path is not writable'], 400);
                return;
            }

            // Auto-analyze filesystem if capacity not provided
            if (empty($data['capacity_total'])) {
                $analysis = $this->analyzeFilesystem($data['path']);
                $data['capacity_total'] = $analysis['total_bytes'] ?? null;
            }

            $poolId = $this->storagePoolRepository->create(
                name: $data['name'],
                path: $data['path'],
                description: $data['description'] ?? null,
                capacityTotal: $data['capacity_total'] ?? null,
                active: $data['active'] ?? true,
                defaultPool: $data['default_pool'] ?? false
            );

            $pool = $this->storagePoolRepository->findById($poolId);
            $this->jsonResponse(['success' => true, 'data' => $pool], 201);

        } catch (\Exception $e) {
            $this->jsonResponse(['error' => 'Failed to create storage pool: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Update storage pool
     * PUT /api/storage-pools/:id
     */
    public function update(): void
    {
        try {
            $id = (int)$this->getRouteParam('id');
            $data = $this->getJsonInput();

            $existingPool = $this->storagePoolRepository->findById($id);
            if (!$existingPool) {
                $this->jsonResponse(['error' => 'Storage pool not found'], 404);
                return;
            }

            // Validation
            if (isset($data['name']) && empty($data['name'])) {
                $this->jsonResponse(['error' => 'Name cannot be empty'], 400);
                return;
            }

            if (isset($data['path'])) {
                if (empty($data['path'])) {
                    $this->jsonResponse(['error' => 'Path cannot be empty'], 400);
                    return;
                }

                if (!file_exists($data['path'])) {
                    $this->jsonResponse(['error' => 'Path does not exist'], 400);
                    return;
                }

                if (!is_dir($data['path'])) {
                    $this->jsonResponse(['error' => 'Path must be a directory'], 400);
                    return;
                }
            }

            $this->storagePoolRepository->update(
                id: $id,
                name: $data['name'] ?? $existingPool['name'],
                path: $data['path'] ?? $existingPool['path'],
                description: $data['description'] ?? $existingPool['description'],
                capacityTotal: $data['capacity_total'] ?? $existingPool['capacity_total'],
                capacityUsed: $data['capacity_used'] ?? $existingPool['capacity_used'],
                active: $data['active'] ?? $existingPool['active'],
                defaultPool: $data['default_pool'] ?? $existingPool['default_pool']
            );

            $pool = $this->storagePoolRepository->findById($id);
            $this->jsonResponse(['success' => true, 'data' => $pool]);

        } catch (\Exception $e) {
            $this->jsonResponse(['error' => 'Failed to update storage pool: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Delete storage pool
     * DELETE /api/storage-pools/:id
     */
    public function delete(): void
    {
        try {
            $id = (int)$this->getRouteParam('id');

            $pool = $this->storagePoolRepository->findById($id);
            if (!$pool) {
                $this->jsonResponse(['error' => 'Storage pool not found'], 404);
                return;
            }

            // Check if pool is in use
            $repositoryCount = $this->storagePoolRepository->getRepositoryCount($id);
            if ($repositoryCount > 0) {
                $this->jsonResponse([
                    'error' => "Cannot delete storage pool with {$repositoryCount} repositories. Please move or delete the repositories first."
                ], 400);
                return;
            }

            // Check if it's the last active pool
            $activePools = $this->storagePoolRepository->findActive();
            if (count($activePools) === 1 && $activePools[0]['id'] === $id) {
                $this->jsonResponse(['error' => 'Cannot delete the last active storage pool'], 400);
                return;
            }

            $this->storagePoolRepository->delete($id);
            $this->jsonResponse(['success' => true, 'message' => 'Storage pool deleted successfully']);

        } catch (\Exception $e) {
            $this->jsonResponse(['error' => 'Failed to delete storage pool: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Analyze filesystem using system commands
     * Returns: type (nfs, local, etc), filesystem, capacity, usage
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
            $dfProcess = new Process(['df', '-P', '-B1', $path]);
            $dfProcess->run();

            if ($dfProcess->isSuccessful()) {
                $output = trim($dfProcess->getOutput());
                $lines = explode("\n", $output);

                if (count($lines) >= 2) {
                    // Parse df output (POSIX format with -P)
                    // Filesystem 1-blocks Used Available Capacity Mounted on
                    $parts = preg_split('/\s+/', $lines[1]);

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
            }

            // Use findmnt to get filesystem type and determine if it's NFS
            $findmntProcess = new Process(['findmnt', '-n', '-o', 'FSTYPE,SOURCE', '-T', $path]);
            $findmntProcess->run();

            if ($findmntProcess->isSuccessful()) {
                $output = trim($findmntProcess->getOutput());
                $parts = preg_split('/\s+/', $output, 2);

                if (count($parts) >= 1) {
                    $fstype = $parts[0];
                    $source = $parts[1] ?? '';

                    // Determine storage type
                    if (in_array($fstype, ['nfs', 'nfs4'])) {
                        $result['type'] = 'nfs';
                        $result['filesystem'] = $source; // NFS uses source as filesystem
                    } elseif (in_array($fstype, ['ext4', 'ext3', 'xfs', 'btrfs', 'zfs'])) {
                        $result['type'] = 'local_disk';
                    } elseif (in_array($fstype, ['cifs', 'smb', 'smbfs'])) {
                        $result['type'] = 'smb';
                    } else {
                        $result['type'] = $fstype;
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
