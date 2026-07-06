<?php

declare(strict_types=1);

namespace PhpBorg\Api\Controller;

use PhpBorg\Application;
use PhpBorg\Repository\ServerRepository;
use PhpBorg\Repository\ArchiveRepository;
use PhpBorg\Repository\JobRepository;
use PhpBorg\Exception\PhpBorgException;

/**
 * Dashboard statistics API controller
 */
class DashboardController extends BaseController
{
    private readonly ServerRepository $serverRepository;
    private readonly ArchiveRepository $archiveRepository;
    private readonly JobRepository $jobRepository;

    public function __construct(Application $app)
    {
        $this->serverRepository = new ServerRepository($app->getConnection());
        $this->archiveRepository = new ArchiveRepository($app->getConnection());
        $this->jobRepository = new JobRepository($app->getConnection());
    }

    /**
     * GET /api/dashboard/stats
     * Get dashboard statistics
     */
    public function stats(): void
    {
        try {
            // Get all servers
            $servers = $this->serverRepository->findAll();
            $activeServers = array_filter($servers, fn($server) => $server->active);

            // Get all archives
            $allArchives = $this->archiveRepository->findAll();

            // Calculate total storage statistics
            $totalOriginalSize = 0;
            $totalCompressedSize = 0;
            $totalDeduplicatedSize = 0;

            foreach ($allArchives as $archive) {
                $totalOriginalSize += $archive->originalSize;
                $totalCompressedSize += $archive->compressedSize;
                $totalDeduplicatedSize += $archive->deduplicatedSize;
            }

            // Calculate compression and deduplication ratios
            $compressionRatio = 0;
            $deduplicationRatio = 0;

            if ($totalOriginalSize > 0) {
                $compressionRatio = round((1 - ($totalCompressedSize / $totalOriginalSize)) * 100, 2);
                $deduplicationRatio = round((1 - ($totalDeduplicatedSize / $totalOriginalSize)) * 100, 2);
            }

            // Bug 28: count job statuses from the DB (GROUP BY status), not from the
            // recent findAll(100) window — a long-running backup was pushed out of that
            // window by frequent stats_collect/storage_pool_analyze jobs and stopped
            // being counted as active.
            $jobStats = $this->jobRepository->getStats();
            $activeJobsCount = ($jobStats['running'] ?? 0) + ($jobStats['pending'] ?? 0);
            $failedJobsCount = $jobStats['failed'] ?? 0;

            // Recent jobs list (window is fine here — it is meant to be "recent")
            $allJobs = $this->jobRepository->findAll();

            // Get recent backups (last 10)
            $recentBackups = array_slice($allArchives, 0, 10);

            $this->success([
                'statistics' => [
                    'total_servers' => count($servers),
                    'active_servers' => count($activeServers),
                    'total_backups' => count($allArchives),
                    'active_jobs' => $activeJobsCount,
                    'failed_jobs' => $failedJobsCount,
                    'storage_used' => $totalDeduplicatedSize,
                    'original_size' => $totalOriginalSize,
                    'compressed_size' => $totalCompressedSize,
                    'compression_ratio' => $compressionRatio,
                    'deduplication_ratio' => $deduplicationRatio,
                ],
                'recent_backups' => array_map(fn($archive) => [
                    'id' => $archive->id,
                    'name' => $archive->name,
                    'archive_id' => $archive->archiveId,
                    'repo_id' => $archive->repoId,
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
                'recent_jobs' => array_map(fn($job) => [
                    'id' => $job->id,
                    'type' => $job->type,
                    'status' => $job->status,
                    'queue' => $job->queue,
                    'created_at' => $job->createdAt->format('Y-m-d H:i:s'),
                    'started_at' => $job->startedAt?->format('Y-m-d H:i:s'),
                    'completed_at' => $job->completedAt?->format('Y-m-d H:i:s'),
                ], array_slice($allJobs, 0, 5)),
            ]);
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'DASHBOARD_STATS_ERROR');
        }
    }
}
