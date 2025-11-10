<?php

declare(strict_types=1);

namespace PhpBorg\Api\Controller;

use PhpBorg\Application;
use PhpBorg\Exception\PhpBorgException;
use PhpBorg\Repository\ArchiveRepository;
use PhpBorg\Repository\BorgRepositoryRepository;
use PhpBorg\Service\Backup\BorgExecutor;
use PhpBorg\Service\Queue\JobQueue;

/**
 * Backup/Archive management API controller
 */
class BackupController extends BaseController
{
    private readonly ArchiveRepository $archiveRepo;
    private readonly BorgRepositoryRepository $repositoryRepo;
    private readonly BorgExecutor $borgExecutor;
    private readonly JobQueue $jobQueue;

    public function __construct(Application $app)
    {
        $this->archiveRepo = $app->getArchiveRepository();
        $this->repositoryRepo = $app->getBorgRepositoryRepository();
        $this->borgExecutor = $app->getBorgExecutor();
        $this->jobQueue = $app->getJobQueue();
    }

    /**
     * GET /api/backups
     * List all backups (archives) with optional filters
     */
    public function list(): void
    {
        try {
            $serverId = isset($_GET['server_id']) ? (int)$_GET['server_id'] : null;
            $repoId = $_GET['repo_id'] ?? null;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;

            // Get archives with server and repository details
            $archiveRows = $this->archiveRepo->findAllWithDetails();

            // Filter by server_id if provided
            if ($serverId !== null) {
                $archiveRows = array_filter($archiveRows, fn($row) => (int)$row['server_id'] === $serverId);
            }

            // Filter by repo_id if provided
            if ($repoId !== null) {
                $archiveRows = array_filter($archiveRows, fn($row) => $row['repo_id'] === $repoId);
            }

            // Limit results
            $archiveRows = array_slice($archiveRows, 0, $limit);

            $this->success([
                'backups' => array_map(fn($row) => $this->serializeArchiveWithDetails($row), $archiveRows),
                'total' => count($archiveRows),
            ]);
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'BACKUP_LIST_ERROR');
        }
    }

    /**
     * GET /api/backups/:id
     * Get backup details
     */
    public function show(): void
    {
        try {
            $backupId = (int) ($_SERVER['ROUTE_PARAMS']['id'] ?? 0);

            if ($backupId <= 0) {
                $this->error('Invalid backup ID', 400, 'INVALID_BACKUP_ID');
                return;
            }

            $archive = $this->archiveRepo->findById($backupId);

            if (!$archive) {
                $this->error('Backup not found', 404, 'BACKUP_NOT_FOUND');
                return;
            }

            $this->success([
                'backup' => $this->serializeArchive($archive)
            ]);
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'BACKUP_DETAIL_ERROR');
        }
    }

    /**
     * POST /api/backups
     * Create a new backup (queue job)
     */
    public function create(): void
    {
        try {
            // Check admin or operator role
            $user = $_SERVER['USER'] ?? null;
            if (!$user || !in_array('ROLE_ADMIN', $user->roles) && !in_array('ROLE_OPERATOR', $user->roles)) {
                $this->error('Admin or Operator role required', 403, 'FORBIDDEN');
                return;
            }

            $data = json_decode(file_get_contents('php://input'), true) ?? [];

            // Validate required fields
            if (!isset($data['server_id'])) {
                $this->error('Missing server_id', 400, 'MISSING_SERVER_ID');
                return;
            }

            $serverId = (int)$data['server_id'];
            $type = $data['type'] ?? 'backup'; // backup, mysql, postgres, mongodb, elasticsearch

            // Create backup job payload
            $payload = [
                'server_id' => $serverId,
                'type' => $type,
            ];

            // Create job in queue
            $jobId = $this->jobQueue->push(
                'backup_create',
                $payload,
                'default',
                3,
                $user->id
            );

            $this->success([
                'job_id' => $jobId,
                'server_id' => $serverId,
                'type' => $type,
                'message' => 'Backup job queued successfully'
            ], 'Backup job created');
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'BACKUP_CREATE_ERROR');
        }
    }

    /**
     * DELETE /api/backups/:id
     * Delete a backup (queue deletion job for worker)
     */
    public function delete(): void
    {
        try {
            // Check admin role
            $user = $_SERVER['USER'] ?? null;
            if (!$user || !in_array('ROLE_ADMIN', $user->roles)) {
                $this->error('Admin role required', 403, 'FORBIDDEN');
                return;
            }

            $backupId = (int) ($_SERVER['ROUTE_PARAMS']['id'] ?? 0);

            if ($backupId <= 0) {
                $this->error('Invalid backup ID', 400, 'INVALID_BACKUP_ID');
                return;
            }

            // Check backup exists
            $archive = $this->archiveRepo->findById($backupId);
            if (!$archive) {
                $this->error('Backup not found', 404, 'BACKUP_NOT_FOUND');
                return;
            }

            // Create archive deletion job payload
            $payload = [
                'archive_id' => $backupId,
                'archive_name' => $archive->name,
                'user_id' => $user->id,
            ];

            // Queue the deletion job for the worker
            $jobId = $this->jobQueue->push(
                'archive_delete',
                $payload,
                'default',
                2, // Priority 2 (high priority for deletions)
                $user->id
            );

            $this->success([
                'job_id' => $jobId,
                'archive_id' => $backupId,
                'archive_name' => $archive->name,
                'message' => 'Archive deletion job queued successfully. The deletion will be processed by the worker.'
            ], 'Archive deletion job created');

        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'BACKUP_DELETE_ERROR');
        }
    }

    /**
     * GET /api/backups/stats
     * Get backup statistics
     */
    public function stats(): void
    {
        try {
            $archives = $this->archiveRepo->findAll();

            $totalBackups = count($archives);
            $totalSize = array_sum(array_map(fn($a) => $a->originalSize, $archives));
            $totalCompressed = array_sum(array_map(fn($a) => $a->compressedSize, $archives));
            $totalDeduplicated = array_sum(array_map(fn($a) => $a->deduplicatedSize, $archives));

            $lastBackup = !empty($archives) ? $archives[0]->end->format('Y-m-d H:i:s') : null;

            $this->success([
                'stats' => [
                    'total_backups' => $totalBackups,
                    'total_original_size' => $totalSize,
                    'total_compressed_size' => $totalCompressed,
                    'total_deduplicated_size' => $totalDeduplicated,
                    'compression_ratio' => $totalSize > 0 ? round((1 - ($totalCompressed / $totalSize)) * 100, 2) : 0,
                    'deduplication_ratio' => $totalSize > 0 ? round((1 - ($totalDeduplicated / $totalSize)) * 100, 2) : 0,
                    'last_backup' => $lastBackup,
                ]
            ]);
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'STATS_ERROR');
        }
    }

    /**
     * Serialize Archive entity to array
     */
    private function serializeArchive($archive): array
    {
        return [
            'id' => $archive->id,
            'repo_id' => $archive->repoId,
            'name' => $archive->name,
            'archive_id' => $archive->archiveId,
            'duration' => $archive->duration,
            'duration_formatted' => $archive->getFormattedDuration(),
            'start' => $archive->start->format('Y-m-d H:i:s'),
            'end' => $archive->end->format('Y-m-d H:i:s'),
            'compressed_size' => $archive->compressedSize,
            'deduplicated_size' => $archive->deduplicatedSize,
            'original_size' => $archive->originalSize,
            'files_count' => $archive->filesCount,
            'compression_ratio' => $archive->getCompressionRatio(),
            'deduplication_ratio' => $archive->getDeduplicationRatio(),
        ];
    }

    /**
     * Serialize archive database row with server and repository details
     */
    private function serializeArchiveWithDetails(array $row): array
    {
        // Calculate ratios
        $originalSize = (int)($row['osize'] ?? 0);
        $compressedSize = (int)($row['csize'] ?? 0);
        $deduplicatedSize = (int)($row['dsize'] ?? 0);
        
        $compressionRatio = $originalSize > 0 ? round((1 - ($compressedSize / $originalSize)) * 100, 2) : 0;
        $deduplicationRatio = $originalSize > 0 ? round((1 - ($deduplicatedSize / $originalSize)) * 100, 2) : 0;
        
        // Format duration
        $duration = (float)($row['dur'] ?? 0);
        $hours = floor($duration / 3600);
        $minutes = floor(($duration % 3600) / 60);
        $seconds = $duration % 60;
        $durationFormatted = sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);

        return [
            'id' => (int)$row['id'],
            'repo_id' => $row['repo_id'],
            'name' => $row['nom'],
            'archive_id' => $row['archive_id'],
            'duration' => $duration,
            'duration_formatted' => $durationFormatted,
            'start' => $row['start'],
            'end' => $row['end'],
            'compressed_size' => $compressedSize,
            'deduplicated_size' => $deduplicatedSize,
            'original_size' => $originalSize,
            'files_count' => (int)($row['nfiles'] ?? 0),
            'compression_ratio' => $compressionRatio,
            'deduplication_ratio' => $deduplicationRatio,
            'server_name' => $row['server_name'] ?? 'Unknown Server',
            'repository_type' => $row['repository_type'] ?? 'backup',
        ];
    }

    /**
     * Get server ID for a repository (helper)
     * TODO: This should query the repositories table
     */
    private function getServerIdForArchive(string $repoId): ?int
    {
        // For now, return null - this would need repositories table access
        return null;
    }
}
