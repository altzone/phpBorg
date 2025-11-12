<?php

declare(strict_types=1);

namespace PhpBorg\Service\Queue\Handlers;

use PhpBorg\Entity\Job;
use PhpBorg\Logger\LoggerInterface;
use PhpBorg\Repository\StoragePoolRepository;
use PhpBorg\Service\Queue\JobQueue;
use Throwable;

/**
 * Handler for storage pool analysis jobs
 * Analyzes filesystem usage and updates pool statistics
 */
final readonly class StoragePoolAnalyzeHandler implements JobHandlerInterface
{
    public function __construct(
        private StoragePoolRepository $storagePoolRepository,
        private LoggerInterface $logger
    ) {
    }

    public function handle(Job $job, JobQueue $queue): string
    {
        $poolId = $job->payload['pool_id'] ?? null;
        $poolPath = $job->payload['pool_path'] ?? null;

        if (!$poolId || !$poolPath) {
            throw new \InvalidArgumentException('Missing pool_id or pool_path in job payload');
        }

        $this->logger->info("Analyzing storage pool #{$poolId}", 'STORAGE_POOL_ANALYZE');

        try {
            // Update progress
            $queue->updateProgress($job->id, 10, "Analyzing filesystem...");

            // Analyze filesystem
            $analysis = $this->analyzeFilesystem($poolPath);

            $queue->updateProgress($job->id, 50, "Updating database...");

            // Update pool with analysis data
            $pool = $this->storagePoolRepository->findById($poolId);
            if (!$pool) {
                throw new \RuntimeException("Storage pool #{$poolId} not found");
            }

            $this->storagePoolRepository->update(
                id: $poolId,
                name: $pool->name,
                path: $pool->path,
                description: $pool->description,
                capacityTotal: $analysis['total_bytes'] ?? $pool->capacityTotal,
                active: $pool->active,
                defaultPool: $pool->defaultPool,
                filesystemType: $analysis['filesystem'] ?? $pool->filesystemType,
                storageType: $analysis['type'] ?? $pool->storageType,
                mountPoint: $analysis['mount_point'] ?? $pool->mountPoint,
                availableBytes: $analysis['available_bytes'] ?? $pool->availableBytes,
                usagePercent: $analysis['usage_percent'] ?? $pool->usagePercent
            );

            $queue->updateProgress($job->id, 100, "Analysis complete");

            $this->logger->info(
                sprintf(
                    "Storage pool #{$poolId} analyzed: %s total, %s available (%d%% used)",
                    $this->formatBytes($analysis['total_bytes'] ?? 0),
                    $this->formatBytes($analysis['available_bytes'] ?? 0),
                    $analysis['usage_percent'] ?? 0
                ),
                'STORAGE_POOL_ANALYZE'
            );

            return json_encode([
                'success' => true,
                'message' => 'Storage pool analyzed successfully',
                'data' => [
                    'pool_id' => $poolId,
                    'total' => $analysis['total'] ?? null,
                    'available' => $analysis['available'] ?? null,
                    'usage_percent' => $analysis['usage_percent'] ?? null,
                    'filesystem' => $analysis['filesystem'] ?? null,
                    'type' => $analysis['type'] ?? null,
                ],
            ]);

        } catch (Throwable $e) {
            $this->logger->error(
                sprintf("Storage pool analysis failed: %s", $e->getMessage()),
                'STORAGE_POOL_ANALYZE'
            );
            throw $e;
        }
    }

    public function supports(string $type): bool
    {
        return $type === 'storage_pool_analyze';
    }

    /**
     * Analyze filesystem using system commands
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

                    // Determine storage type
                    if (in_array($fstype, ['nfs', 'nfs4'])) {
                        $result['type'] = 'nfs';
                        $result['filesystem'] = $source;
                    } elseif (in_array($fstype, ['ext4', 'ext3', 'xfs', 'btrfs', 'zfs'])) {
                        $result['type'] = 'local_disk';
                        $result['filesystem'] = $fstype;
                    } elseif (in_array($fstype, ['cifs', 'smb', 'smbfs'])) {
                        $result['type'] = 'smb';
                        $result['filesystem'] = $source;
                    } else {
                        $result['type'] = $fstype;
                        $result['filesystem'] = $fstype;
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger->error("Filesystem analysis failed: {$e->getMessage()}", 'STORAGE_POOL_ANALYZE');
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
