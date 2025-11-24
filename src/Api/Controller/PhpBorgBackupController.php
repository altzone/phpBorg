<?php

declare(strict_types=1);

namespace PhpBorg\Api\Controller;

use PhpBorg\Application;
use PhpBorg\Repository\PhpBorgBackupRepository;
use PhpBorg\Service\Queue\JobQueue;

/**
 * API Controller for phpBorg self-backup management
 *
 * Endpoints:
 * - GET    /api/phpborg-backups              List all backups
 * - GET    /api/phpborg-backups/:id          Get backup details
 * - GET    /api/phpborg-backups/stats        Get backup statistics
 * - POST   /api/phpborg-backups              Create manual backup
 * - POST   /api/phpborg-backups/:id/restore  Restore from backup
 * - POST   /api/phpborg-backups/cleanup      Trigger cleanup
 * - DELETE /api/phpborg-backups/:id          Delete backup
 * - GET    /api/phpborg-backups/:id/download Download backup file
 */
final class PhpBorgBackupController extends BaseController
{
    private readonly PhpBorgBackupRepository $backupRepository;
    private readonly JobQueue $jobQueue;

    public function __construct(Application $app)
    {
        $this->backupRepository = $app->getPhpBorgBackupRepository();
        $this->jobQueue = $app->getJobQueue();
    }

    /**
     * GET /api/phpborg-backups - List all backups
     */
    public function index(): void
    {
        try {
            $backups = $this->backupRepository->findAll();

            $data = array_map(fn($backup) => $backup->toArray(), $backups);

            $this->success($data, 'Backups retrieved successfully');

        } catch (\Exception $e) {
            $this->error('Failed to retrieve backups: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/phpborg-backups/:id - Get backup details
     */
    public function show(): void
    {
        $id = (int)($_SERVER['ROUTE_PARAMS']['id'] ?? 0);

        if ($id <= 0) {
            $this->error('Invalid backup ID', 400);
            return;
        }

        try {
            $backup = $this->backupRepository->findById($id);

            if (!$backup) {
                $this->error('Backup not found', 404);
                return;
            }

            $this->success($backup->toArray(), 'Backup retrieved successfully');

        } catch (\Exception $e) {
            $this->error('Failed to retrieve backup: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/phpborg-backups/stats - Get backup statistics
     */
    public function stats(): void
    {
        try {
            $stats = $this->backupRepository->getStats();

            // Add human-readable total size
            $stats['total_size_human'] = $this->formatBytes($stats['total_size']);

            $this->success($stats, 'Statistics retrieved successfully');

        } catch (\Exception $e) {
            $this->error('Failed to retrieve statistics: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/phpborg-backups - Create manual backup
     *
     * Body: {
     *   "notes": "Optional description"
     * }
     */
    public function create(): void
    {
        $body = $this->getJsonBody();
        $notes = $body['notes'] ?? null;

        try {
            // Create job for backup creation
            $jobId = $this->jobQueue->push('phpborg_backup_create', [
                'backup_type' => 'manual',
                'notes' => $notes
            ], 'default', 1); // default queue, 1 max attempt

            $this->success([
                'job_id' => $jobId,
                'message' => 'Backup creation started'
            ], 'Backup job created successfully', 202);

        } catch (\Exception $e) {
            $this->error('Failed to create backup: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/phpborg-backups/:id/restore - Restore from backup
     *
     * Body: {
     *   "create_pre_restore_backup": true
     * }
     */
    public function restore(): void
    {
        $id = (int)($_SERVER['ROUTE_PARAMS']['id'] ?? 0);

        if ($id <= 0) {
            $this->error('Invalid backup ID', 400);
            return;
        }

        $body = $this->getJsonBody();
        $createPreRestoreBackup = $body['create_pre_restore_backup'] ?? true;

        try {
            $backup = $this->backupRepository->findById($id);

            if (!$backup) {
                $this->error('Backup not found', 404);
                return;
            }

            if (!$backup->exists()) {
                $this->error('Backup file not found on disk', 404);
                return;
            }

            // Create job for restore
            $jobId = $this->jobQueue->push('phpborg_backup_restore', [
                'backup_id' => $id,
                'create_pre_restore_backup' => $createPreRestoreBackup
            ], 'default', 1); // default queue, 1 max attempt

            $this->success([
                'job_id' => $jobId,
                'backup' => $backup->toArray(),
                'message' => 'Restore operation started'
            ], 'Restore job created successfully', 202);

        } catch (\Exception $e) {
            $this->error('Failed to start restore: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/phpborg-backups/cleanup - Trigger cleanup
     */
    public function cleanup(): void
    {
        try {
            // Create job for cleanup
            $jobId = $this->jobQueue->push('phpborg_backup_cleanup', [], 'default', 3); // default queue, 3 max attempts

            $this->success([
                'job_id' => $jobId,
                'message' => 'Cleanup started'
            ], 'Cleanup job created successfully', 202);

        } catch (\Exception $e) {
            $this->error('Failed to trigger cleanup: ' . $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/phpborg-backups/:id - Delete backup
     */
    public function delete(): void
    {
        $id = (int)($_SERVER['ROUTE_PARAMS']['id'] ?? 0);

        if ($id <= 0) {
            $this->error('Invalid backup ID', 400);
            return;
        }

        try {
            $backup = $this->backupRepository->findById($id);

            if (!$backup) {
                $this->error('Backup not found', 404);
                return;
            }

            // Delete file from disk
            if (file_exists($backup->filepath)) {
                if (!unlink($backup->filepath)) {
                    $this->error('Failed to delete backup file', 500);
                    return;
                }
            }

            // Delete database record
            $this->backupRepository->delete($id);

            $this->success([
                'deleted_backup' => $backup->toArray()
            ], 'Backup deleted successfully');

        } catch (\Exception $e) {
            $this->error('Failed to delete backup: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/phpborg-backups/:id/download - Download backup file
     */
    public function download(): void
    {
        $id = (int)($_SERVER['ROUTE_PARAMS']['id'] ?? 0);

        if ($id <= 0) {
            $this->error('Invalid backup ID', 400);
            return;
        }

        try {
            $backup = $this->backupRepository->findById($id);

            if (!$backup) {
                $this->error('Backup not found', 404);
                return;
            }

            if (!$backup->exists()) {
                $this->error('Backup file not found on disk', 404);
                return;
            }

            // Set headers for file download
            header('Content-Type: application/gzip');
            header('Content-Disposition: attachment; filename="' . $backup->filename . '"');
            header('Content-Length: ' . $backup->sizeBytes);

            // Output file content
            readfile($backup->filepath);
            exit;

        } catch (\Exception $e) {
            $this->error('Failed to download backup: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Format bytes to human-readable size
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size = (float)$bytes;
        $unitIndex = 0;

        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        return round($size, 2) . ' ' . $units[$unitIndex];
    }
}
