<?php

declare(strict_types=1);

namespace PhpBorg\Api\Controller;

use PhpBorg\Application;
use PhpBorg\Exception\PhpBorgException;
use PhpBorg\Service\Server\ServerManager;
use PhpBorg\Service\Queue\JobQueue;
use PhpBorg\Repository\ArchiveRepository;
use PhpBorg\Repository\ServerStatsRepository;
use PhpBorg\Repository\BorgRepositoryRepository;
use PhpBorg\Repository\BackupJobRepository;
use PhpBorg\Repository\ServerRepository;

/**
 * Server management API controller
 */
class ServerController extends BaseController
{
    private readonly Application $app;
    private readonly ServerManager $serverManager;
    private readonly JobQueue $jobQueue;
    private readonly ArchiveRepository $archiveRepository;
    private readonly ServerStatsRepository $statsRepository;
    private readonly BorgRepositoryRepository $repositoryRepository;
    private readonly BackupJobRepository $backupJobRepository;
    private readonly ServerRepository $serverRepository;
    private readonly \PhpBorg\Repository\AgentRepository $agentRepository;

    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->serverManager = $app->getServerManager();
        $this->jobQueue = $app->getJobQueue();
        $this->archiveRepository = new ArchiveRepository($app->getConnection());
        $this->statsRepository = $app->getServerStatsRepository();
        $this->repositoryRepository = $app->getBorgRepositoryRepository();
        $this->backupJobRepository = $app->getBackupJobRepository();
        $this->serverRepository = $app->getServerRepository();
        $this->agentRepository = $app->getAgentRepository();
    }

    /**
     * GET /api/servers
     * List all servers with their latest stats
     */
    public function list(): void
    {
        try {
            $servers = $this->serverManager->getAllServers();

            // Get latest stats for all servers
            $serverIds = array_map(fn($server) => $server->id, $servers);
            $statsMap = [];

            if (!empty($serverIds)) {
                $statsMap = $this->statsRepository->getLatestStatsForServers($serverIds);
            }

            $this->success([
                'servers' => array_map(function($server) use ($statsMap) {
                    $stats = $statsMap[$server->id] ?? null;

                    // Get repository count for this server
                    $repositories = $this->serverManager->getRepositoriesForServer($server->id);
                    $repositoryCount = count($repositories);

                    return [
                        'id' => $server->id,
                        'name' => $server->name,
                        'hostname' => $server->host,  // Database field is 'host'
                        'port' => $server->port,
                        'username' => 'root',  // Not stored in DB yet
                        'backupType' => $server->backupType,
                        'description' => null,  // Not stored in DB yet
                        'active' => $server->active,
                        'repository_count' => $repositoryCount,
                        // Agent information
                        'agent' => $this->formatAgentInfo($server),
                        // Add server stats
                        'stats' => $stats ? [
                            'os_distribution' => $stats['os_distribution'],
                            'os_version' => $stats['os_version'],
                            'kernel_version' => $stats['kernel_version'],
                            'hostname' => $stats['hostname'],
                            'architecture' => $stats['architecture'],
                            'cpu_cores' => $stats['cpu_cores'],
                            'cpu_model' => $stats['cpu_model'],
                            'cpu_load_1' => $stats['cpu_load_1'],
                            'cpu_usage_percent' => $stats['cpu_usage_percent'],
                            'memory_total_mb' => $stats['memory_total_mb'],
                            'memory_used_mb' => $stats['memory_used_mb'],
                            'memory_percent' => $stats['memory_percent'],
                            'disk_total_gb' => $stats['disk_total_gb'],
                            'disk_used_gb' => $stats['disk_used_gb'],
                            'disk_percent' => $stats['disk_percent'],
                            'uptime_seconds' => $stats['uptime_seconds'],
                            'uptime_human' => $stats['uptime_human'],
                            'ip_address' => $stats['ip_address'],
                            'collected_at' => $stats['collected_at'],
                        ] : null,
                    ];
                }, $servers),
                'total' => count($servers),
            ]);
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'SERVER_LIST_ERROR');
        }
    }

    /**
     * GET /api/servers/:id
     * Get server details with repositories
     */
    public function show(): void
    {
        try {
            $serverId = (int) ($_SERVER['ROUTE_PARAMS']['id'] ?? 0);

            if ($serverId <= 0) {
                $this->error('Invalid server ID', 400, 'INVALID_SERVER_ID');
                return;
            }

            $server = $this->serverManager->getServerById($serverId);

            if (!$server) {
                $this->error('Server not found', 404, 'SERVER_NOT_FOUND');
                return;
            }

            // Get repositories for this server
            $repositories = $this->serverManager->getRepositoriesForServer($serverId);

            // Get backup statistics for this server
            $totalBackups = $this->archiveRepository->countByServerId($serverId);
            $storageStats = $this->archiveRepository->getStorageStatsByServerId($serverId);

            // Get recent backups for this server
            $recentBackups = $this->archiveRepository->findRecentByServerId($serverId, 5);

            $this->success([
                'server' => [
                    'id' => $server->id,
                    'name' => $server->name,
                    'hostname' => $server->host,  // Database field is 'host'
                    'port' => $server->port,
                    'username' => 'root',  // Not stored in DB yet
                    'backupType' => $server->backupType,
                    'description' => null,  // Not stored in DB yet
                    'active' => $server->active,
                    'created_at' => $server->createdAt?->format('Y-m-d H:i:s'),
                    'updated_at' => $server->updatedAt?->format('Y-m-d H:i:s'),
                    // Agent information
                    'agent' => $this->formatAgentInfo($server),
                ],
                'repositories' => array_map(fn($repo) => [
                    'id' => $repo->id,
                    'type' => $repo->type,
                    'repo_path' => $repo->repoPath,
                    'compression' => $repo->compression,
                    'encryption' => $repo->encryption,
                    // Retention policy
                    'retention' => [
                        'keep_daily' => $repo->keepDaily,
                        'keep_weekly' => $repo->keepWeekly,
                        'keep_monthly' => $repo->keepMonthly,
                        'keep_yearly' => $repo->keepYearly,
                    ],
                    'modified' => $repo->modified->format('Y-m-d H:i:s'),
                    'created_at' => $repo->modified->format('Y-m-d H:i:s'),
                ], $repositories),
                'statistics' => [
                    'total_backups' => $totalBackups,
                    'total_repositories' => count($repositories),
                    'storage_used' => $storageStats['total_deduplicated_size'],
                    'original_size' => $storageStats['total_original_size'],
                    'compressed_size' => $storageStats['total_compressed_size'],
                ],
                'recent_backups' => array_map(fn($archive) => [
                    'id' => $archive->id,
                    'name' => $archive->name,
                    'archive_id' => $archive->archiveId,
                    'start' => $archive->start->format('Y-m-d H:i:s'),
                    'end' => $archive->end->format('Y-m-d H:i:s'),
                    'duration' => $archive->duration,
                    'duration_formatted' => $archive->getFormattedDuration(),
                    'original_size' => $archive->originalSize,
                    'compressed_size' => $archive->compressedSize,
                    'deduplicated_size' => $archive->deduplicatedSize,
                    'files_count' => $archive->filesCount,
                    'compression_ratio' => $archive->getCompressionRatio(),
                    'deduplication_ratio' => $archive->getDeduplicationRatio(),
                ], $recentBackups),
            ]);
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'SERVER_DETAIL_ERROR');
        }
    }

    /**
     * POST /api/servers
     * Create a new server
     */
    public function create(): void
    {
        try {
            // Check admin role
            $user = $_SERVER['USER'] ?? null;
            if (!$user || !in_array('ROLE_ADMIN', $user->roles)) {
                $this->error('Admin role required', 403, 'FORBIDDEN');
                return;
            }

            $data = $this->getJsonBody();

            // Validate required fields
            $this->validateRequired($data, ['name', 'hostname', 'username', 'backupType']);

            // Validate port
            $port = (int) ($data['port'] ?? 22);
            if ($port <= 0 || $port > 65535) {
                $this->error('Invalid port number', 'INVALID_PORT', 400);
                return;
            }

            // Validate backupType
            $backupType = $data['backupType'] ?? 'internal';
            if (!in_array($backupType, ['internal', 'external'])) {
                $this->error('Invalid backup type. Must be internal or external', 'INVALID_BACKUP_TYPE', 400);
                return;
            }

            // Create server
            $serverId = $this->serverManager->createServer(
                name: $data['name'],
                hostname: $data['hostname'],
                port: $port,
                username: $data['username'],
                description: $data['description'] ?? null,
                backupType: $backupType
            );

            // Get created server
            $server = $this->serverManager->getServerById($serverId);

            // Auto-create setup job for background configuration
            $setupPayload = [
                'server_id' => $serverId,
                'server_name' => $server->name,
                'hostname' => $server->host,
                'port' => $server->port,
                'ssh_user' => $data['username'] ?? 'root',
                'ssh_password' => $data['ssh_password'] ?? null,
                'ssh_key_path' => $data['ssh_key_path'] ?? null,
                'borg_repo_path' => $data['borg_repo_path'] ?? '/backup/borg',
                'compression' => $data['compression'] ?? 'lz4',
                'create_repositories' => true,
            ];

            $jobId = $this->jobQueue->push(
                'server_setup',
                $setupPayload,
                'default',
                3,
                $user->id
            );

            $this->success(
                [
                    'server' => [
                        'id' => $server->id,
                        'name' => $server->name,
                        'hostname' => $server->host,  // Database field is 'host'
                        'port' => $server->port,
                        'username' => 'root',
                        'backupType' => $server->backupType,
                        'description' => null,
                        'active' => $server->active,
                        'created_at' => $server->createdAt?->format('Y-m-d H:i:s'),
                    ],
                    'setup_job_id' => $jobId,
                    'message' => 'Server created and setup job queued'
                ],
                'Server created successfully - setup in progress',
                201
            );
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'SERVER_CREATE_ERROR');
        }
    }

    /**
     * PUT /api/servers/:id
     * Update server
     */
    public function update(): void
    {
        try {
            // Check admin role
            $user = $_SERVER['USER'] ?? null;
            if (!$user || !in_array('ROLE_ADMIN', $user->roles)) {
                $this->error('Admin role required', 403, 'FORBIDDEN');
                return;
            }

            $serverId = (int) ($_SERVER['ROUTE_PARAMS']['id'] ?? 0);

            if ($serverId <= 0) {
                $this->error('Invalid server ID', 400, 'INVALID_SERVER_ID');
                return;
            }

            // Check server exists
            $server = $this->serverManager->getServerById($serverId);
            if (!$server) {
                $this->error('Server not found', 404, 'SERVER_NOT_FOUND');
                return;
            }

            $data = $this->getJsonBody();

            // Validate port if provided
            if (isset($data['port'])) {
                $port = (int) $data['port'];
                if ($port <= 0 || $port > 65535) {
                    $this->error('Invalid port number', 400, 'INVALID_PORT');
                    return;
                }
            }

            // Update server
            $this->serverManager->updateServer(
                serverId: $serverId,
                name: $data['name'] ?? null,
                hostname: $data['hostname'] ?? null,
                port: isset($data['port']) ? (int) $data['port'] : null,
                username: $data['username'] ?? null,
                description: $data['description'] ?? null,
                active: isset($data['active']) ? (bool) $data['active'] : null
            );

            // Get updated server
            $server = $this->serverManager->getServerById($serverId);

            $this->success([
                'server' => [
                    'id' => $server->id,
                    'name' => $server->name,
                    'hostname' => $server->host,  // Database field is 'host'
                    'port' => $server->port,
                    'username' => 'root',
                    'backupType' => $server->backupType,
                    'description' => null,
                    'active' => $server->active,
                    'created_at' => $server->createdAt?->format('Y-m-d H:i:s'),
                    'updated_at' => $server->updatedAt?->format('Y-m-d H:i:s'),
                ],
            ], 'Server updated successfully');
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'SERVER_UPDATE_ERROR');
        }
    }

    /**
     * DELETE /api/servers/:id
     * Delete server
     * Query param: type=archive|full (default: archive)
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

            $serverId = (int) ($_SERVER['ROUTE_PARAMS']['id'] ?? 0);

            if ($serverId <= 0) {
                $this->error('Invalid server ID', 400, 'INVALID_SERVER_ID');
                return;
            }

            // Check server exists
            $server = $this->serverManager->getServerById($serverId);
            if (!$server) {
                $this->error('Server not found', 404, 'SERVER_NOT_FOUND');
                return;
            }

            // Get delete type from query params
            $deleteType = $_GET['type'] ?? 'archive';

            if ($deleteType === 'archive') {
                // Archive: Just set active=0 (or add status='archived' if we add that column)
                $this->serverManager->archiveServer($serverId);

                $this->success(
                    ['id' => $serverId, 'type' => 'archive'],
                    'Server archived successfully'
                );
            } elseif ($deleteType === 'full') {
                // Full Delete: rm -rf repos + clean DB + delete server

                // Get all repositories for this server
                $repositories = $this->serverManager->getRepositoriesForServer($serverId);

                // Delete repository directories from filesystem
                foreach ($repositories as $repo) {
                    if (!empty($repo->path) && file_exists($repo->path)) {
                        // rm -rf the repository directory
                        $this->recursiveDelete($repo->path);
                    }
                }

                // Delete from database in correct order (respect foreign keys)
                // 1. Delete archives (backups)
                $this->archiveRepository->deleteByServerId($serverId);

                // 2. Delete backup jobs
                $this->backupJobRepository->deleteByServerId($serverId);

                // 3. Delete repositories
                $this->repositoryRepository->deleteByServerId($serverId);

                // 4. Delete server stats
                $this->statsRepository->deleteByServerId($serverId);

                // 5. Delete associated agent if exists
                if ($server->agentUuid) {
                    $agent = $this->agentRepository->findByUuid($server->agentUuid);
                    if ($agent) {
                        $this->agentRepository->delete((int)$agent['id']);
                    }
                }

                // 6. Delete server from servers table
                $this->serverRepository->delete($serverId);

                $this->success(
                    ['id' => $serverId, 'type' => 'full'],
                    'Server and all data deleted successfully'
                );
            } else {
                $this->error('Invalid delete type. Use "archive" or "full"', 400, 'INVALID_DELETE_TYPE');
            }
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'SERVER_DELETE_ERROR');
        }
    }

    /**
     * Recursively delete a directory
     */
    private function recursiveDelete(string $dir): void
    {
        if (!file_exists($dir)) {
            return;
        }

        if (!is_dir($dir)) {
            unlink($dir);
            return;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }

    /**
     * GET /api/servers/:id/delete-stats
     * Get statistics about what will be deleted (for confirmation)
     */
    public function deleteStats(): void
    {
        try {
            $serverId = (int) ($_SERVER['ROUTE_PARAMS']['id'] ?? 0);

            if ($serverId <= 0) {
                $this->error('Invalid server ID', 400, 'INVALID_SERVER_ID');
                return;
            }

            // Get repositories count
            $repositories = $this->serverManager->getRepositoriesForServer($serverId);
            $repositoryCount = count($repositories);

            // Get archives count and total size
            $archiveStats = $this->archiveRepository->getStatsByServerId($serverId);
            $archiveCount = $archiveStats['count'] ?? 0;
            $totalSize = $archiveStats['total_deduplicated_size'] ?? 0;

            // Get backup jobs count
            $backupJobs = 0;
            foreach ($repositories as $repo) {
                $jobs = $this->backupJobRepository->findByRepositoryId($repo->id);
                $backupJobs += count($jobs);
            }

            $this->success([
                'repositories' => $repositoryCount,
                'archives' => $archiveCount,
                'backup_jobs' => $backupJobs,
                'total_size_bytes' => $totalSize,
                'total_size_gb' => round($totalSize / 1024 / 1024 / 1024, 2),
            ]);
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'DELETE_STATS_ERROR');
        }
    }

    /**
     * GET /api/servers/:id/repositories
     * Get repositories for a server
     */
    public function repositories(): void
    {
        try {
            $serverId = (int) ($_SERVER['ROUTE_PARAMS']['id'] ?? 0);

            if ($serverId <= 0) {
                $this->error('Invalid server ID', 400, 'INVALID_SERVER_ID');
                return;
            }

            $repositories = $this->serverManager->getRepositoriesForServer($serverId);
            $archiveRepo = $this->archiveRepository;

            $this->success([
                'repositories' => array_map(function($repo) use ($archiveRepo) {
                    // Extract name from path (last segment)
                    $pathParts = explode('/', trim($repo->repoPath, '/'));
                    $name = end($pathParts) ?: 'Repository #' . $repo->id;

                    // Get archives for this repo
                    $archives = $archiveRepo->findByRepositoryId($repo->repoId);
                    $archiveCount = count($archives);

                    // Get last backup date
                    $lastBackup = null;
                    if ($archiveCount > 0) {
                        // Archives are ordered by end date DESC
                        $lastBackup = $archives[0]->end->format('Y-m-d H:i:s');
                    }

                    // Calculate stats from archives if repository stats are null
                    $size = $repo->size;
                    $compressedSize = $repo->compressedSize;
                    $deduplicatedSize = $repo->deduplicatedSize;

                    // If repository stats are null but we have archives, use the latest archive stats
                    // (This is the deduplicated size for the whole repo, approximated from latest archive)
                    if (($size === null || $size === 0) && $archiveCount > 0) {
                        // Sum original sizes of all archives for total size
                        $size = array_reduce($archives, fn($sum, $a) => $sum + ($a->originalSize ?? 0), 0);
                        $compressedSize = array_reduce($archives, fn($sum, $a) => $sum + ($a->compressedSize ?? 0), 0);
                        // For deduplicated size, use the latest archive's value (most accurate for repo)
                        $deduplicatedSize = $archives[0]->deduplicatedSize ?? 0;
                    }

                    return [
                        'id' => $repo->id,
                        'server_id' => $repo->serverId,
                        'repo_id' => $repo->repoId,
                        'name' => $name,
                        'type' => $repo->type,
                        'repo_path' => $repo->repoPath,
                        'backup_path' => $repo->backupPath,
                        'compression' => $repo->compression,
                        'encryption' => $repo->encryption,
                        'archive_count' => $archiveCount,
                        'last_backup_at' => $lastBackup,
                        'size' => $size,
                        'deduplicated_size' => $deduplicatedSize,
                        'created_at' => $repo->modified->format('Y-m-d H:i:s'),
                        // Retention policy
                        'retention' => [
                            'keep_daily' => $repo->keepDaily,
                            'keep_weekly' => $repo->keepWeekly,
                            'keep_monthly' => $repo->keepMonthly,
                            'keep_yearly' => $repo->keepYearly,
                        ],
                        // Statistics
                        'stats' => [
                            'size' => $size,
                            'compressed_size' => $compressedSize,
                            'deduplicated_size' => $deduplicatedSize,
                            'total_unique_chunks' => $repo->totalUniqueChunks,
                            'total_chunks' => $repo->totalChunks,
                        ],
                    ];
                }, $repositories),
                'total' => count($repositories),
            ]);
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'REPOSITORIES_ERROR');
        }
    }

    /**
     * POST /api/servers/:id/collect-stats
     * Trigger stats collection for a server
     */
    public function collectStats(): void
    {
        try {
            $serverId = (int) ($_SERVER['ROUTE_PARAMS']['id'] ?? 0);

            if ($serverId <= 0) {
                $this->error('Invalid server ID', 400, 'INVALID_SERVER_ID');
                return;
            }

            // Check server exists
            $server = $this->serverManager->getServerById($serverId);
            if (!$server) {
                $this->error('Server not found', 404, 'SERVER_NOT_FOUND');
                return;
            }

            if (!$server->active) {
                $this->error('Cannot collect stats from inactive server', 400, 'SERVER_INACTIVE');
                return;
            }

            // Get current user
            $user = $_SERVER['USER'] ?? null;

            // Create stats collection job
            $jobId = $this->jobQueue->push(
                'server_stats_collect',
                [
                    'server_id' => $serverId,
                    'server_name' => $server->name,
                ],
                'default',
                1, // Priority
                $user?->id
            );

            $this->success([
                'job_id' => $jobId,
                'server_id' => $serverId,
                'server_name' => $server->name,
                'message' => 'Stats collection job queued'
            ], 'Stats collection started');
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'STATS_COLLECTION_ERROR');
        }
    }

    /**
     * GET /api/servers/:id/capabilities
     * Get server capabilities (databases, snapshots, docker, etc.)
     */
    public function capabilities(): void
    {
        try {
            $serverId = (int) ($_SERVER['ROUTE_PARAMS']['id'] ?? 0);

            if ($serverId <= 0) {
                $this->error('Invalid server ID', 400, 'INVALID_SERVER_ID');
                return;
            }

            // Get server with capabilities
            $server = $this->serverRepository->findById($serverId);
            if (!$server) {
                $this->error('Server not found', 404, 'SERVER_NOT_FOUND');
                return;
            }

            // Parse capabilities_data JSON
            $capabilitiesData = null;
            if ($server->capabilitiesData) {
                $capabilitiesData = json_decode($server->capabilitiesData, true);
            }

            $this->success([
                'server_id' => $serverId,
                'server_name' => $server->name,
                'capabilities_detected' => (bool) $server->capabilitiesDetected,
                'capabilities_detected_at' => $server->capabilitiesDetectedAt?->format('Y-m-d H:i:s'),
                'capabilities' => $capabilitiesData
            ]);
        } catch (\Exception $e) {
            $this->error($e->getMessage(), 500, 'CAPABILITIES_FETCH_ERROR');
        }
    }

    /**
     * POST /api/servers/:id/detect-capabilities
     * Trigger capabilities detection job
     */
    public function detectCapabilities(): void
    {
        try {
            $serverId = (int) ($_SERVER['ROUTE_PARAMS']['id'] ?? 0);

            if ($serverId <= 0) {
                $this->error('Invalid server ID', 400, 'INVALID_SERVER_ID');
                return;
            }

            // Check server exists
            $server = $this->serverManager->getServerById($serverId);
            if (!$server) {
                $this->error('Server not found', 404, 'SERVER_NOT_FOUND');
                return;
            }

            if (!$server->active) {
                $this->error('Cannot detect capabilities on inactive server', 400, 'SERVER_INACTIVE');
                return;
            }

            // Get current user
            $user = $_SERVER['USER'] ?? null;

            // Create capabilities detection job
            $jobId = $this->jobQueue->push(
                'capabilities_detection',
                [
                    'server_id' => $serverId,
                    'server_name' => $server->name,
                ],
                'default',
                1, // Priority
                $user?->id
            );

            $this->success([
                'job_id' => $jobId,
                'server_id' => $serverId,
                'server_name' => $server->name,
                'message' => 'Capabilities detection job queued'
            ], 'Capabilities detection started');
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'CAPABILITIES_DETECTION_ERROR');
        }
    }

    /**
     * Format agent information for API response
     *
     * @param \PhpBorg\Entity\Server $server
     * @return array|null
     */
    private function formatAgentInfo($server): ?array
    {
        // Return null if server doesn't use agent
        if ($server->connectionMode !== 'agent') {
            return null;
        }

        // Calculate if agent is online (heartbeat within 5 minutes)
        $isOnline = false;
        $lastSeenAgo = null;

        if ($server->agentLastHeartbeat) {
            $now = new \DateTime();
            $diff = $now->getTimestamp() - $server->agentLastHeartbeat->getTimestamp();
            $isOnline = $diff < 300; // 5 minutes

            // Format "last seen" human readable
            if ($diff < 60) {
                $lastSeenAgo = $diff . 's';
            } elseif ($diff < 3600) {
                $lastSeenAgo = floor($diff / 60) . 'm';
            } elseif ($diff < 86400) {
                $lastSeenAgo = floor($diff / 3600) . 'h';
            } else {
                $lastSeenAgo = floor($diff / 86400) . 'd';
            }
        }

        // Get latest available version
        $latestVersion = DownloadController::getLatestAgentVersion();
        $needsUpdate = false;

        if ($server->agentVersion && $latestVersion) {
            $needsUpdate = version_compare($server->agentVersion, $latestVersion, '<');
        }

        return [
            'uuid' => $server->agentUuid,
            'status' => $server->agentStatus,
            'status_label' => $server->getAgentStatusLabel(),
            'version' => $server->agentVersion,
            'latest_version' => $latestVersion,
            'needs_update' => $needsUpdate,
            'is_online' => $isOnline,
            'last_heartbeat' => $server->agentLastHeartbeat?->format('Y-m-d H:i:s'),
            'last_seen_ago' => $lastSeenAgo,
            'connection_mode' => $server->connectionMode,
        ];
    }

    /**
     * POST /api/servers/:id/agent/update
     * Trigger agent update task
     */
    public function triggerAgentUpdate(int $id): void
    {
        try {
            // Check admin role
            $user = $_SERVER['USER'] ?? null;
            if (!$user || !in_array('ROLE_ADMIN', $user->roles)) {
                $this->error('Admin role required', 403, 'FORBIDDEN');
                return;
            }

            // Get server
            $server = $this->serverManager->getServerById($id);
            if (!$server) {
                $this->error('Server not found', 404, 'SERVER_NOT_FOUND');
                return;
            }

            // Check server uses agent mode
            if ($server->connectionMode !== 'agent') {
                $this->error('Server does not use agent mode', 400, 'NOT_AGENT_MODE');
                return;
            }

            // Get agent by UUID
            if (!$server->agentUuid) {
                $this->error('Server has no agent UUID', 400, 'NO_AGENT_UUID');
                return;
            }

            $agent = $this->agentRepository->findByUuid($server->agentUuid);
            if (!$agent) {
                $this->error('Agent not found', 404, 'AGENT_NOT_FOUND');
                return;
            }

            // Get latest version and checksum
            $latestVersion = DownloadController::getLatestAgentVersion();
            if (!$latestVersion) {
                $this->error('Cannot determine latest agent version', 500, 'VERSION_ERROR');
                return;
            }

            $binaryPath = dirname(__DIR__, 3) . '/releases/agent/phpborg-agent';
            if (!file_exists($binaryPath)) {
                $this->error('No agent binary available. Run build first.', 404, 'NO_BINARY');
                return;
            }

            $checksum = hash_file('sha256', $binaryPath);

            // Insert update task into agent_tasks
            $connection = $this->app->getConnection();
            $connection->executeUpdate(
                'INSERT INTO agent_tasks (agent_id, type, payload, status, created_at)
                 VALUES (?, ?, ?, ?, NOW())',
                [
                    (int)$agent['id'],
                    'agent_update',
                    json_encode([
                        'server_id' => $id,
                        'version' => $latestVersion,
                        'checksum' => $checksum,
                    ]),
                    'pending'
                ]
            );

            $taskId = $connection->getLastInsertId();

            $this->success([
                'task_id' => $taskId,
                'server_id' => $id,
                'message' => 'Agent update task queued'
            ], 'Update task created');

        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'UPDATE_TRIGGER_ERROR');
        }
    }

    /**
     * GET /api/servers/:id/stats-history
     * Get server stats history for graphs
     */
    public function statsHistory(): void
    {
        try {
            $serverId = (int) ($_SERVER['ROUTE_PARAMS']['id'] ?? 0);
            $hours = (int) ($_GET['hours'] ?? 24);
            $limit = (int) ($_GET['limit'] ?? 100);

            if ($serverId <= 0) {
                $this->error('Invalid server ID', 400, 'INVALID_SERVER_ID');
                return;
            }

            // Validate hours (max 7 days = 168 hours)
            $hours = min(max($hours, 1), 168);
            $limit = min(max($limit, 10), 500);

            $history = $this->statsRepository->getStatsHistory($serverId, $hours, $limit);

            // Format for charts (reverse to get chronological order)
            $history = array_reverse($history);

            $this->success([
                'server_id' => $serverId,
                'hours' => $hours,
                'count' => count($history),
                'history' => array_map(fn($stat) => [
                    'timestamp' => $stat['collected_at'],
                    'cpu_usage' => (float) ($stat['cpu_usage_percent'] ?? 0),
                    'cpu_load_1' => (float) ($stat['cpu_load_1'] ?? 0),
                    'cpu_load_5' => (float) ($stat['cpu_load_5'] ?? 0),
                    'cpu_load_15' => (float) ($stat['cpu_load_15'] ?? 0),
                    'memory_percent' => (float) ($stat['memory_percent'] ?? 0),
                    'memory_used_mb' => (int) ($stat['memory_used_mb'] ?? 0),
                    'memory_total_mb' => (int) ($stat['memory_total_mb'] ?? 0),
                    'disk_percent' => (float) ($stat['disk_percent'] ?? 0),
                    'disk_used_gb' => (float) ($stat['disk_used_gb'] ?? 0),
                    'disk_total_gb' => (float) ($stat['disk_total_gb'] ?? 0),
                    'swap_percent' => (float) ($stat['swap_percent'] ?? 0),
                ], $history),
            ]);
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'STATS_HISTORY_ERROR');
        }
    }

    /**
     * GET /api/servers/:id/full-details
     * Get comprehensive server details for dashboard
     */
    public function fullDetails(): void
    {
        try {
            $serverId = (int) ($_SERVER['ROUTE_PARAMS']['id'] ?? 0);

            if ($serverId <= 0) {
                $this->error('Invalid server ID', 400, 'INVALID_SERVER_ID');
                return;
            }

            $server = $this->serverManager->getServerById($serverId);
            if (!$server) {
                $this->error('Server not found', 404, 'SERVER_NOT_FOUND');
                return;
            }

            // Get latest stats
            $latestStats = $this->statsRepository->getLatestStats($serverId);

            // Get repositories
            $repositories = $this->serverManager->getRepositoriesForServer($serverId);

            // Get backup statistics
            $totalBackups = $this->archiveRepository->countByServerId($serverId);
            $storageStats = $this->archiveRepository->getStorageStatsByServerId($serverId);

            // Get recent backups (last 10)
            $recentBackups = $this->archiveRepository->findRecentByServerId($serverId, 10);

            // Get storage pools used by this server's repositories
            $storagePoolRepo = $this->app->getStoragePoolRepository();
            $allPools = $storagePoolRepo->findAll();
            $serverPools = [];

            foreach ($repositories as $repo) {
                foreach ($allPools as $pool) {
                    if (strpos($repo->repoPath, $pool->path) === 0) {
                        $poolId = $pool->id;
                        if (!isset($serverPools[$poolId])) {
                            $serverPools[$poolId] = [
                                'id' => $pool->id,
                                'name' => $pool->name,
                                'path' => $pool->path,
                                'total_size_gb' => round($pool->totalSize / 1024 / 1024 / 1024, 2),
                                'used_size_gb' => round($pool->usedSize / 1024 / 1024 / 1024, 2),
                                'free_size_gb' => round($pool->freeSize / 1024 / 1024 / 1024, 2),
                                'usage_percent' => $pool->usagePercent,
                                'repository_count' => 0,
                            ];
                        }
                        $serverPools[$poolId]['repository_count']++;
                        break;
                    }
                }
            }

            // Get capabilities
            $serverEntity = $this->serverRepository->findById($serverId);
            $capabilities = null;
            if ($serverEntity && $serverEntity->capabilitiesData) {
                $capabilities = json_decode($serverEntity->capabilitiesData, true);
            }

            // Calculate backup success rate (last 30 days)
            $backupStats = $this->backupJobRepository->getStatsByServerId($serverId, 30);

            // Get scheduled backup jobs for this server's repositories
            $scheduledJobs = [];
            foreach ($repositories as $repo) {
                $repoJobs = $this->backupJobRepository->findByRepositoryId($repo->id);
                foreach ($repoJobs as $job) {
                    $scheduledJobs[] = [
                        'id' => $job->id,
                        'name' => $job->name,
                        'repository_id' => $job->repositoryId,
                        'schedule_type' => $job->scheduleType,
                        'schedule_time' => $job->scheduleTime,
                        'schedule_day_of_week' => $job->scheduleDayOfWeek,
                        'schedule_day_of_month' => $job->scheduleDayOfMonth,
                        'cron_expression' => $job->cronExpression,
                        'enabled' => $job->enabled,
                        'last_run_at' => $job->lastRunAt?->format('Y-m-d H:i:s'),
                        'next_run_at' => $job->nextRunAt?->format('Y-m-d H:i:s'),
                        'last_status' => $job->lastStatus,
                    ];
                }
            }

            $this->success([
                'server' => [
                    'id' => $server->id,
                    'name' => $server->name,
                    'hostname' => $server->host,
                    'port' => $server->port,
                    'username' => 'root',
                    'backupType' => $server->backupType,
                    'active' => $server->active,
                    'created_at' => $server->createdAt?->format('Y-m-d H:i:s'),
                    'updated_at' => $server->updatedAt?->format('Y-m-d H:i:s'),
                    'agent' => $this->formatAgentInfo($server),
                ],
                'system' => $latestStats ? [
                    'os' => [
                        'distribution' => $latestStats['os_distribution'],
                        'version' => $latestStats['os_version'],
                        'kernel' => $latestStats['kernel_version'],
                        'architecture' => $latestStats['architecture'],
                        'hostname' => $latestStats['hostname'],
                    ],
                    'cpu' => [
                        'model' => $latestStats['cpu_model'],
                        'cores' => (int) $latestStats['cpu_cores'],
                        'usage_percent' => (float) $latestStats['cpu_usage_percent'],
                        'load_1' => (float) $latestStats['cpu_load_1'],
                        'load_5' => (float) $latestStats['cpu_load_5'],
                        'load_15' => (float) $latestStats['cpu_load_15'],
                    ],
                    'memory' => [
                        'total_mb' => (int) $latestStats['memory_total_mb'],
                        'used_mb' => (int) $latestStats['memory_used_mb'],
                        'free_mb' => (int) $latestStats['memory_free_mb'],
                        'available_mb' => (int) $latestStats['memory_available_mb'],
                        'percent' => (float) $latestStats['memory_percent'],
                    ],
                    'swap' => [
                        'total_mb' => (int) $latestStats['swap_total_mb'],
                        'used_mb' => (int) $latestStats['swap_used_mb'],
                        'percent' => (float) $latestStats['swap_percent'],
                    ],
                    'disk' => [
                        'total_gb' => (float) $latestStats['disk_total_gb'],
                        'used_gb' => (float) $latestStats['disk_used_gb'],
                        'free_gb' => (float) $latestStats['disk_free_gb'],
                        'percent' => (float) $latestStats['disk_percent'],
                        'mount_point' => $latestStats['disk_mount_point'],
                    ],
                    'network' => [
                        'ip_address' => $latestStats['ip_address'],
                    ],
                    'uptime' => [
                        'seconds' => (int) $latestStats['uptime_seconds'],
                        'human' => $latestStats['uptime_human'],
                        'boot_time' => $latestStats['boot_time'],
                    ],
                    'collected_at' => $latestStats['collected_at'],
                ] : null,
                'backup_statistics' => [
                    'total_backups' => $totalBackups,
                    'total_repositories' => count($repositories),
                    'storage_used' => $storageStats['total_deduplicated_size'],
                    'original_size' => $storageStats['total_original_size'],
                    'compressed_size' => $storageStats['total_compressed_size'],
                    'deduplication_ratio' => $storageStats['total_original_size'] > 0
                        ? round((1 - ($storageStats['total_deduplicated_size'] / $storageStats['total_original_size'])) * 100, 1)
                        : 0,
                    'success_rate' => $backupStats['success_rate'] ?? 100,
                    'last_30_days' => [
                        'total' => $backupStats['total'] ?? 0,
                        'successful' => $backupStats['successful'] ?? 0,
                        'failed' => $backupStats['failed'] ?? 0,
                    ],
                ],
                'repositories' => array_map(fn($repo) => [
                    'id' => $repo->id,
                    'type' => $repo->type,
                    'repo_path' => $repo->repoPath,
                    'backup_path' => $repo->backupPath,
                    'compression' => $repo->compression,
                    'encryption' => $repo->encryption,
                    'retention' => [
                        'keep_daily' => $repo->keepDaily,
                        'keep_weekly' => $repo->keepWeekly,
                        'keep_monthly' => $repo->keepMonthly,
                        'keep_yearly' => $repo->keepYearly,
                    ],
                    'stats' => [
                        'size' => $repo->size,
                        'compressed_size' => $repo->compressedSize,
                        'deduplicated_size' => $repo->deduplicatedSize,
                    ],
                    'modified' => $repo->modified->format('Y-m-d H:i:s'),
                ], $repositories),
                'storage_pools' => array_values($serverPools),
                'recent_backups' => array_map(fn($archive) => [
                    'id' => $archive->id,
                    'name' => $archive->name,
                    'archive_id' => $archive->archiveId,
                    'start' => $archive->start->format('Y-m-d H:i:s'),
                    'end' => $archive->end->format('Y-m-d H:i:s'),
                    'duration' => $archive->duration,
                    'duration_formatted' => $archive->getFormattedDuration(),
                    'original_size' => $archive->originalSize,
                    'compressed_size' => $archive->compressedSize,
                    'deduplicated_size' => $archive->deduplicatedSize,
                    'files_count' => $archive->filesCount,
                    'compression_ratio' => $archive->getCompressionRatio(),
                    'deduplication_ratio' => $archive->getDeduplicationRatio(),
                ], $recentBackups),
                'capabilities' => $capabilities,
                'scheduled_jobs' => $scheduledJobs,
            ]);
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'FULL_DETAILS_ERROR');
        }
    }
}
