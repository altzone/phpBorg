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

            // Get active jobs (pending or running)
            $allJobs = $this->jobRepository->findAll();
            $activeJobs = array_filter($allJobs, fn($job) => in_array($job->status, ['pending', 'running']));
            $failedJobs = array_filter($allJobs, fn($job) => $job->status === 'failed');

            // Get recent backups (last 10)
            $recentBackups = array_slice($allArchives, 0, 10);

            $this->success([
                'statistics' => [
                    'total_servers' => count($servers),
                    'active_servers' => count($activeServers),
                    'total_backups' => count($allArchives),
                    'active_jobs' => count($activeJobs),
                    'failed_jobs' => count($failedJobs),
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
