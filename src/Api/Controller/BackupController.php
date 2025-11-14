<?php

declare(strict_types=1);

namespace PhpBorg\Api\Controller;

use PhpBorg\Application;
use PhpBorg\Exception\PhpBorgException;
use PhpBorg\Repository\ArchiveRepository;
use PhpBorg\Repository\ArchiveMountRepository;
use PhpBorg\Repository\BorgRepositoryRepository;
use PhpBorg\Service\Backup\BorgExecutor;
use PhpBorg\Service\Queue\JobQueue;

/**
 * Backup/Archive management API controller
 */
class BackupController extends BaseController
{
    private readonly ArchiveRepository $archiveRepo;
    private readonly ArchiveMountRepository $mountRepo;
    private readonly BorgRepositoryRepository $repositoryRepo;
    private readonly BorgExecutor $borgExecutor;
    private readonly JobQueue $jobQueue;

    public function __construct(Application $app)
    {
        $this->archiveRepo = $app->getArchiveRepository();
        $this->mountRepo = $app->getArchiveMountRepository();
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
            'server_id' => $archive->serverId,
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
            'server_id' => (int)($row['server_id'] ?? 0),
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
            'mount_id' => isset($row['mount_id']) ? (int)$row['mount_id'] : null,
            'mount_status' => $row['mount_status'] ?? null,
            'mount_path' => $row['mount_path'] ?? null,
        ];
    }

    /**
     * POST /api/backups/:id/mount
     * Mount an archive for browsing/restore (queue job for worker)
     */
    public function mount(): void
    {
        try {
            // Check admin or operator role
            $user = $_SERVER['USER'] ?? null;
            if (!$user || !in_array('ROLE_ADMIN', $user->roles) && !in_array('ROLE_OPERATOR', $user->roles)) {
                $this->error('Admin or Operator role required', 403, 'FORBIDDEN');
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

            // Check if already mounted
            $existingMount = $this->mountRepo->findByArchiveId($backupId);
            if ($existingMount && $existingMount->status === 'mounted') {
                // Verify the mount actually exists in /proc/mounts
                if ($this->isMounted($existingMount->mountPath)) {
                    $this->success([
                        'mount_id' => $existingMount->id,
                        'archive_id' => $backupId,
                        'mount_path' => $existingMount->mountPath,
                        'status' => 'already_mounted',
                        'message' => 'Archive is already mounted'
                    ], 'Archive already mounted');
                    return;
                } else {
                    // Mount record exists but actual mount is gone - clean it up
                    $this->mountRepo->deleteById($existingMount->id);
                }
            }

            // Create mount job payload
            $payload = [
                'archive_id' => $backupId,
                'action' => 'mount',
                'user_id' => $user->id,
            ];

            // Queue the mount job for the worker
            $jobId = $this->jobQueue->push(
                'archive_mount',
                $payload,
                'default',
                2, // Priority 2 (high)
                $user->id
            );

            $this->success([
                'job_id' => $jobId,
                'archive_id' => $backupId,
                'archive_name' => $archive->name,
                'message' => 'Archive mount job queued successfully. The mount will be processed by the worker.'
            ], 'Archive mount job created');

        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'BACKUP_MOUNT_ERROR');
        }
    }

    /**
     * POST /api/backups/:id/unmount
     * Unmount a mounted archive (queue job for worker)
     */
    public function unmount(): void
    {
        try {
            // Check admin or operator role
            $user = $_SERVER['USER'] ?? null;
            if (!$user || !in_array('ROLE_ADMIN', $user->roles) && !in_array('ROLE_OPERATOR', $user->roles)) {
                $this->error('Admin or Operator role required', 403, 'FORBIDDEN');
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

            // Check if mounted
            $mount = $this->mountRepo->findByArchiveId($backupId);
            if (!$mount) {
                $this->error('Archive is not mounted', 400, 'NOT_MOUNTED');
                return;
            }

            // Create unmount job payload
            $payload = [
                'archive_id' => $backupId,
                'action' => 'unmount',
                'user_id' => $user->id,
            ];

            // Queue the unmount job for the worker
            $jobId = $this->jobQueue->push(
                'archive_mount',
                $payload,
                'default',
                2, // Priority 2 (high)
                $user->id
            );

            $this->success([
                'job_id' => $jobId,
                'archive_id' => $backupId,
                'archive_name' => $archive->name,
                'message' => 'Archive unmount job queued successfully. The unmount will be processed by the worker.'
            ], 'Archive unmount job created');

        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'BACKUP_UNMOUNT_ERROR');
        }
    }

    /**
     * GET /api/backups/:id/browse?path=/some/path
     * Browse files in a mounted archive
     */
    public function browse(): void
    {
        try {
            $backupId = (int) ($_SERVER['ROUTE_PARAMS']['id'] ?? 0);
            $relativePath = $_GET['path'] ?? '/';

            if ($backupId <= 0) {
                $this->error('Invalid backup ID', 400, 'INVALID_BACKUP_ID');
                return;
            }

            // Get mount info
            $mount = $this->mountRepo->findByArchiveId($backupId);
            if (!$mount || $mount->status !== 'mounted') {
                $this->error('Archive is not mounted', 400, 'NOT_MOUNTED');
                return;
            }

            // Check if mount point is actually accessible (FUSE mount may be dead)
            $mountPath = rtrim($mount->mountPath, '/');
            if (!$this->isMountAccessible($mountPath)) {
                // Mount is dead, clean it up
                $this->logger->warning("Mount point {$mountPath} is not accessible, cleaning up", 'BROWSE');
                $this->mountRepo->deleteByArchiveId($backupId);
                $this->error('Mount has expired or is no longer accessible. Please mount the archive again.', 410, 'MOUNT_EXPIRED');
                return;
            }

            // Update last access time
            $this->mountRepo->updateLastAccessByArchiveId($backupId);

            // Security: Validate and sanitize path to prevent directory traversal
            $relativePath = $this->sanitizePath($relativePath);
            $fullPath = rtrim($mount->mountPath, '/') . '/' . ltrim($relativePath, '/');

            // Normalize the path to remove any ./ or ../ segments
            $normalizedPath = $this->normalizePath($fullPath);

            // Ensure path is within mount directory (without realpath for FUSE compatibility)
            $mountPath = rtrim($mount->mountPath, '/');
            if (strpos($normalizedPath, $mountPath) !== 0) {
                $this->error('Invalid path', 400, 'INVALID_PATH');
                return;
            }

            // Check if path exists
            if (!file_exists($normalizedPath)) {
                $this->error('Path not found', 404, 'PATH_NOT_FOUND');
                return;
            }

            // If it's a file, return file info
            if (is_file($normalizedPath)) {
                $this->success([
                    'type' => 'file',
                    'path' => $relativePath,
                    'name' => basename($normalizedPath),
                    'size' => filesize($normalizedPath),
                    'modified' => date('Y-m-d H:i:s', filemtime($normalizedPath)),
                    'mime_type' => mime_content_type($normalizedPath),
                ]);
                return;
            }

            // If it's a directory, list contents
            if (!is_dir($normalizedPath)) {
                $this->error('Path is not a directory', 400, 'NOT_A_DIRECTORY');
                return;
            }

            $items = [];
            $entries = scandir($normalizedPath);

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $entryPath = $normalizedPath . '/' . $entry;
                $entryRelativePath = rtrim($relativePath, '/') . '/' . $entry;

                // Check if it's a symlink (skip them as they cause issues with FUSE)
                if (is_link($entryPath)) {
                    $item = [
                        'name' => $entry,
                        'path' => $entryRelativePath,
                        'type' => 'symlink',
                        'size' => 0,
                        'modified' => date('Y-m-d H:i:s', time()),
                        'mime_type' => 'inode/symlink',
                    ];
                } else {
                    $isDir = is_dir($entryPath);
                    $mtime = @filemtime($entryPath);
                    $size = is_file($entryPath) ? @filesize($entryPath) : 0;

                    $item = [
                        'name' => $entry,
                        'path' => $entryRelativePath,
                        'type' => $isDir ? 'directory' : 'file',
                        'size' => $size !== false ? $size : 0,
                        'modified' => $mtime !== false ? date('Y-m-d H:i:s', $mtime) : date('Y-m-d H:i:s', time()),
                    ];

                    if ($item['type'] === 'file') {
                        $mimeType = @mime_content_type($entryPath);
                        $item['mime_type'] = $mimeType !== false ? $mimeType : 'application/octet-stream';
                    }
                }

                $items[] = $item;
            }

            // Sort: directories first, then alphabetically
            usort($items, function ($a, $b) {
                if ($a['type'] === $b['type']) {
                    return strcasecmp($a['name'], $b['name']);
                }
                return $a['type'] === 'directory' ? -1 : 1;
            });

            $this->success([
                'path' => $relativePath,
                'items' => $items,
                'total' => count($items),
            ]);

        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'BROWSE_ERROR');
        } catch (\Exception $e) {
            $this->error('Failed to browse archive: ' . $e->getMessage(), 500, 'BROWSE_ERROR');
        }
    }

    /**
     * GET /api/backups/:id/download?path=/some/file
     * Download a file from a mounted archive
     */
    public function download(): void
    {
        try {
            $backupId = (int) ($_SERVER['ROUTE_PARAMS']['id'] ?? 0);
            $relativePath = $_GET['path'] ?? '';

            if ($backupId <= 0) {
                $this->error('Invalid backup ID', 400, 'INVALID_BACKUP_ID');
                return;
            }

            if (empty($relativePath)) {
                $this->error('Path parameter is required', 400, 'MISSING_PATH');
                return;
            }

            // Get mount info
            $mount = $this->mountRepo->findByArchiveId($backupId);
            if (!$mount || $mount->status !== 'mounted') {
                $this->error('Archive is not mounted', 400, 'NOT_MOUNTED');
                return;
            }

            // Update last access time
            $this->mountRepo->updateLastAccessByArchiveId($backupId);

            // Security: Validate and sanitize path
            $relativePath = $this->sanitizePath($relativePath);
            $fullPath = rtrim($mount->mountPath, '/') . '/' . ltrim($relativePath, '/');

            // Normalize the path to remove any ./ or ../ segments
            $normalizedPath = $this->normalizePath($fullPath);

            // Ensure path is within mount directory (without realpath for FUSE compatibility)
            $mountPath = rtrim($mount->mountPath, '/');
            if (strpos($normalizedPath, $mountPath) !== 0) {
                $this->error('Invalid path', 400, 'INVALID_PATH');
                return;
            }

            // Check if file exists and is a file
            if (!file_exists($normalizedPath)) {
                $this->error('File not found', 404, 'FILE_NOT_FOUND');
                return;
            }

            if (!is_file($normalizedPath)) {
                $this->error('Path is not a file', 400, 'NOT_A_FILE');
                return;
            }

            // Stream file to browser
            $filename = basename($normalizedPath);
            $filesize = filesize($normalizedPath);
            $mimeType = mime_content_type($normalizedPath) ?: 'application/octet-stream';

            header('Content-Type: ' . $mimeType);
            header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
            header('Content-Length: ' . $filesize);
            header('Cache-Control: no-cache, must-revalidate');
            header('Pragma: no-cache');

            // Stream file in chunks to handle large files
            $handle = fopen($normalizedPath, 'rb');
            if ($handle === false) {
                $this->error('Failed to open file', 500, 'FILE_READ_ERROR');
                return;
            }

            while (!feof($handle)) {
                echo fread($handle, 8192);
                flush();
            }

            fclose($handle);
            exit;

        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'DOWNLOAD_ERROR');
        } catch (\Exception $e) {
            $this->error('Failed to download file: ' . $e->getMessage(), 500, 'DOWNLOAD_ERROR');
        }
    }

    /**
     * Preview file from mounted archive
     */
    public function preview(): void
    {
        try {
            $backupId = (int) ($_SERVER['ROUTE_PARAMS']['id'] ?? 0);
            $relativePath = $_GET['path'] ?? '';

            if ($backupId <= 0) {
                $this->error('Invalid backup ID', 400, 'INVALID_BACKUP_ID');
                return;
            }

            if (empty($relativePath)) {
                $this->error('Path parameter is required', 400, 'MISSING_PATH');
                return;
            }

            // Get mount info
            $mount = $this->mountRepo->findByArchiveId($backupId);
            if (!$mount || $mount->status !== 'mounted') {
                $this->error('Archive is not mounted', 400, 'NOT_MOUNTED');
                return;
            }

            // Update last access time
            $this->mountRepo->updateLastAccessByArchiveId($backupId);

            // Security: Validate and sanitize path
            $relativePath = $this->sanitizePath($relativePath);
            $fullPath = rtrim($mount->mountPath, '/') . '/' . ltrim($relativePath, '/');

            // Normalize the path to remove any ./ or ../ segments
            $normalizedPath = $this->normalizePath($fullPath);

            // Ensure path is within mount directory
            $mountPath = rtrim($mount->mountPath, '/');
            if (strpos($normalizedPath, $mountPath) !== 0) {
                $this->error('Invalid path', 400, 'INVALID_PATH');
                return;
            }

            // Check if file exists and is a file
            if (!file_exists($normalizedPath)) {
                $this->error('File not found', 404, 'FILE_NOT_FOUND');
                return;
            }

            if (!is_file($normalizedPath)) {
                $this->error('Path is not a file', 400, 'NOT_A_FILE');
                return;
            }

            // Get file info
            $filename = basename($normalizedPath);
            $filesize = filesize($normalizedPath);
            $mimeType = mime_content_type($normalizedPath) ?: 'application/octet-stream';

            // Determine if file is previewable
            $isImage = str_starts_with($mimeType, 'image/');
            $isText = str_starts_with($mimeType, 'text/') ||
                      in_array($mimeType, [
                          'application/json',
                          'application/xml',
                          'application/javascript',
                          'application/x-httpd-php',
                          'application/x-sh',
                      ]);

            // Check file size limit for text files (2MB max)
            $maxTextSize = 2 * 1024 * 1024;

            if ($isImage) {
                // Stream image directly with inline disposition
                header('Content-Type: ' . $mimeType);
                header('Content-Disposition: inline; filename="' . addslashes($filename) . '"');
                header('Content-Length: ' . $filesize);
                header('Cache-Control: public, max-age=3600');

                readfile($normalizedPath);
                exit;

            } elseif ($isText && $filesize <= $maxTextSize) {
                // Read text content and return as JSON
                $content = file_get_contents($normalizedPath);

                if ($content === false) {
                    $this->error('Failed to read file', 500, 'FILE_READ_ERROR');
                    return;
                }

                // Detect encoding and convert to UTF-8 if needed
                $encoding = mb_detect_encoding($content, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
                if ($encoding && $encoding !== 'UTF-8') {
                    $content = mb_convert_encoding($content, 'UTF-8', $encoding);
                }

                $this->success([
                    'type' => 'text',
                    'content' => $content,
                    'filename' => $filename,
                    'size' => $filesize,
                    'mime_type' => $mimeType,
                ]);

            } else {
                // File is not previewable or too large
                $reason = $filesize > $maxTextSize ? 'File too large for preview' : 'File type not supported';
                $this->error($reason, 400, 'NOT_PREVIEWABLE');
            }

        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'PREVIEW_ERROR');
        } catch (\Exception $e) {
            $this->error('Failed to preview file: ' . $e->getMessage(), 500, 'PREVIEW_ERROR');
        }
    }

    /**
     * Sanitize path to prevent directory traversal attacks
     */
    private function sanitizePath(string $path): string
    {
        // Remove any ../ or ..\ sequences
        $path = str_replace(['../', '..\\'], '', $path);

        // Normalize slashes
        $path = str_replace('\\', '/', $path);

        // Remove multiple slashes
        $path = preg_replace('#/+#', '/', $path);

        return $path;
    }

    /**
     * Normalize path to resolve ./ and ../ segments (FUSE-compatible)
     * Alternative to realpath() that works with FUSE mounts
     */
    private function normalizePath(string $path): string
    {
        // Normalize slashes
        $path = str_replace('\\', '/', $path);

        // Split path into parts
        $parts = explode('/', $path);
        $normalized = [];

        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                // Skip empty parts and current directory references
                continue;
            }

            if ($part === '..') {
                // Go up one directory if possible
                if (count($normalized) > 0) {
                    array_pop($normalized);
                }
            } else {
                // Add normal directory/file name
                $normalized[] = $part;
            }
        }

        // Rebuild path
        $result = '/' . implode('/', $normalized);

        // Remove trailing slash except for root
        if ($result !== '/' && substr($result, -1) === '/') {
            $result = substr($result, 0, -1);
        }

        return $result;
    }

    /**
     * Check if a directory is a mount point
     */
    private function isMounted(string $path): bool
    {
        // Check if path is in /proc/mounts
        $mounts = @file_get_contents('/proc/mounts');
        if ($mounts === false) {
            return false;
        }
        return strpos($mounts, $path) !== false;
    }

    /**
     * POST /api/backups/:id/restore
     * Restore files from an archive to destination server
     *
     * Required payload:
     * - server_id: Destination server ID
     * - files: Array of file paths to restore
     * - restore_mode: 'in_place' | 'alternate' | 'suffix'
     * - destination: Custom destination path (required for 'alternate' mode)
     * - overwrite_mode: 'always' | 'newer' | 'never' | 'rename'
     * - preserve_permissions: boolean
     * - preserve_owner: boolean
     * - verify_checksums: boolean
     * - dry_run: boolean (optional, simulate restore)
     */
    public function restore(): void
    {
        try {
            // Require admin or operator role
            $currentUser = $_SERVER['USER'] ?? null;
            if (!$currentUser || !in_array('ROLE_ADMIN', $currentUser->roles) && !in_array('ROLE_OPERATOR', $currentUser->roles)) {
                $this->error('Admin or Operator role required for restore operations', 403, 'FORBIDDEN');
                return;
            }

            $archiveId = (int) ($_SERVER['ROUTE_PARAMS']['id'] ?? 0);
            if ($archiveId <= 0) {
                $this->error('Invalid archive ID', 400, 'INVALID_ARCHIVE_ID');
                return;
            }

            // Get JSON payload
            $data = $this->getJsonBody();

            // Validate required fields
            if (!isset($data['server_id']) || !isset($data['files']) || !isset($data['restore_mode'])) {
                $this->error('Missing required fields: server_id, files, restore_mode', 400, 'MISSING_FIELDS');
                return;
            }

            $serverId = (int) $data['server_id'];
            $files = $data['files'];
            $restoreMode = $data['restore_mode'];
            $destination = $data['destination'] ?? null;
            $overwriteMode = $data['overwrite_mode'] ?? 'newer';
            $preservePermissions = $data['preserve_permissions'] ?? true;
            $preserveOwner = $data['preserve_owner'] ?? true;
            $verifyChecksums = $data['verify_checksums'] ?? false;
            $dryRun = $data['dry_run'] ?? false;

            // Validate files array
            if (!is_array($files) || empty($files)) {
                $this->error('Files must be a non-empty array', 400, 'INVALID_FILES');
                return;
            }

            // Validate restore mode
            if (!in_array($restoreMode, ['in_place', 'alternate', 'suffix'])) {
                $this->error('Invalid restore_mode. Must be: in_place, alternate, or suffix', 400, 'INVALID_RESTORE_MODE');
                return;
            }

            // Validate alternate mode has destination
            if ($restoreMode === 'alternate' && empty($destination)) {
                $this->error('Alternate mode requires a destination path', 400, 'MISSING_DESTINATION');
                return;
            }

            // Extra confirmation required for in_place mode
            if ($restoreMode === 'in_place' && !($data['confirm_overwrite'] ?? false)) {
                $this->error('In-place restore requires explicit confirmation (confirm_overwrite: true)', 400, 'CONFIRMATION_REQUIRED');
                return;
            }

            // Check if archive exists
            $archive = $this->archiveRepo->findById($archiveId);
            if (!$archive) {
                $this->error('Archive not found', 404, 'ARCHIVE_NOT_FOUND');
                return;
            }

            // Create restore job
            $jobId = $this->jobQueue->push(
                type: 'archive_restore',
                payload: [
                    'archive_id' => $archiveId,
                    'server_id' => $serverId,
                    'files' => $files,
                    'restore_mode' => $restoreMode,
                    'destination' => $destination,
                    'overwrite_mode' => $overwriteMode,
                    'preserve_permissions' => $preservePermissions,
                    'preserve_owner' => $preserveOwner,
                    'verify_checksums' => $verifyChecksums,
                    'dry_run' => $dryRun,
                ],
                queue: 'default',
                maxAttempts: 2,
                createdBy: $currentUser->id ?? null
            );

            $this->success([
                'message' => $dryRun
                    ? 'Restore simulation job created'
                    : 'Restore job created successfully',
                'job_id' => $jobId,
                'archive_id' => $archiveId,
                'server_id' => $serverId,
                'files_count' => count($files),
                'restore_mode' => $restoreMode,
                'dry_run' => $dryRun,
            ]);
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'RESTORE_ERROR');
        }
    }

    /**
     * Check if a FUSE mount point is accessible
     * Returns false if mount is dead (Transport endpoint is not connected)
     */
    private function isMountAccessible(string $mountPath): bool
    {
        // Try to access the mount point
        // If FUSE mount is dead, this will fail with "Transport endpoint is not connected"
        $output = [];
        $returnCode = 0;

        // Use ls command to test accessibility without PHP warnings
        exec("ls " . escapeshellarg($mountPath) . " 2>&1", $output, $returnCode);

        // Check if the output contains the telltale sign of a dead FUSE mount
        $outputStr = implode("\n", $output);
        if (strpos($outputStr, 'Transport endpoint is not connected') !== false) {
            return false;
        }

        // Return code 0 means accessible
        return $returnCode === 0;
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
