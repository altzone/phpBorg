<?php

declare(strict_types=1);

namespace PhpBorg\Service\Queue\Handlers;

use PhpBorg\Entity\Job;
use PhpBorg\Logger\LoggerInterface;
use PhpBorg\Repository\ArchiveRepository;
use PhpBorg\Repository\ArchiveMountRepository;
use PhpBorg\Repository\BackupJobRepository;
use PhpBorg\Repository\BorgRepositoryRepository;
use PhpBorg\Service\Queue\JobQueue;
use PhpBorg\Service\Server\ServerStatsComputer;

/**
 * Repository Delete Handler
 *
 * Deletes a Borg repository and all associated data:
 * - Removes repository directory from disk (rm -rf)
 * - Deletes all mounts
 * - Deletes all archives
 * - Deletes all backup jobs
 * - Deletes repository record
 */
final class RepositoryDeleteHandler implements JobHandlerInterface
{
    public function __construct(
        private readonly BorgRepositoryRepository $repositoryRepo,
        private readonly ArchiveRepository $archiveRepo,
        private readonly ArchiveMountRepository $mountRepo,
        private readonly BackupJobRepository $jobRepo,
        private readonly LoggerInterface $logger,
        private readonly ServerStatsComputer $statsComputer
    ) {
    }

    public function handle(Job $job, JobQueue $queue): string
    {
        $repositoryId = (int)$job->payload['repository_id'];

        $this->logger->info("Starting repository deletion for ID: {$repositoryId}", 'JOB');

        // Step 1: Load repository
        $queue->updateProgress($job->id, 10, "Loading repository...");
        $repository = $this->repositoryRepo->findById($repositoryId);

        if (!$repository) {
            throw new \Exception("Repository ID {$repositoryId} not found");
        }

        $this->logger->info("Deleting repository: {$repository->repoPath}", 'JOB');

        // Step 2: Get all archives for stats
        $queue->updateProgress($job->id, 20, "Counting archives...");
        $archives = $this->archiveRepo->findByRepositoryId($repository->repoId);
        $archiveCount = count($archives);

        $this->logger->info("Repository has {$archiveCount} archives", 'JOB');

        // Step 3: Physically delete repository directory
        $queue->updateProgress($job->id, 40, "Deleting repository directory from disk...");

        if (file_exists($repository->repoPath)) {
            // Use recursive directory deletion
            $success = $this->deleteDirectory($repository->repoPath);

            if (!$success) {
                throw new \Exception("Failed to delete repository directory: {$repository->repoPath}");
            }

            $this->logger->info("Repository directory deleted: {$repository->repoPath}", 'JOB');
        } else {
            $this->logger->warning("Repository directory does not exist: {$repository->repoPath}", 'JOB');
        }

        // Step 4: Delete all mounts for this repository's archives
        $queue->updateProgress($job->id, 60, "Deleting archive mounts...");
        $mountsDeleted = 0;
        foreach ($archives as $archive) {
            $mount = $this->mountRepo->findByArchiveId($archive->id);
            if ($mount !== null) {
                $this->mountRepo->deleteById($mount->id);
                $mountsDeleted++;
            }
        }
        $this->logger->info("Deleted {$mountsDeleted} mounts", 'JOB');

        // Step 5: Delete all archives
        $queue->updateProgress($job->id, 70, "Deleting archive records...");
        foreach ($archives as $archive) {
            $this->archiveRepo->deleteById($archive->id);
        }
        $this->logger->info("Deleted {$archiveCount} archive records", 'JOB');

        // Step 6: Delete all backup jobs for this repository
        $queue->updateProgress($job->id, 85, "Deleting backup jobs...");
        $jobs = $this->jobRepo->findByRepositoryId($repositoryId);
        $jobsDeleted = count($jobs);
        foreach ($jobs as $backupJob) {
            $this->jobRepo->delete($backupJob->id);
        }
        $this->logger->info("Deleted {$jobsDeleted} backup jobs", 'JOB');

        // Step 7: Delete repository record
        $queue->updateProgress($job->id, 95, "Deleting repository record...");
        $this->repositoryRepo->delete($repositoryId);
        $this->logger->info("Repository record deleted", 'JOB');

        // Success
        $queue->updateProgress($job->id, 100, "Repository deleted successfully");

        // Update pre-computed stats for server
        try {
            $this->statsComputer->onBackupDeleted($repository->serverId);
            $this->logger->info("Updated server stats after repository deletion", 'JOB');
        } catch (\Exception $statsEx) {
            $this->logger->error("Failed to update server stats: {$statsEx->getMessage()}", 'JOB');
        }

        $sizeFreed = $repository->deduplicatedSize;

        return sprintf(
            "Repository deleted successfully: %d archives, %d jobs, %s freed",
            $archiveCount,
            $jobsDeleted,
            $this->formatBytes($sizeFreed)
        );
    }

    /**
     * Recursively delete a directory and all its contents
     */
    private function deleteDirectory(string $dir): bool
    {
        if (!is_dir($dir)) {
            // Directory doesn't exist, consider it deleted
            return true;
        }

        // Try PHP method first
        $scan = scandir($dir);
        if ($scan !== false) {
            $files = array_diff($scan, ['.', '..']);

            foreach ($files as $file) {
                $path = $dir . '/' . $file;

                if (is_dir($path)) {
                    $this->deleteDirectory($path);
                } else {
                    @unlink($path);
                }
            }

            if (@rmdir($dir)) {
                return true;
            }
        }

        // Fallback to sudo rm -rf (borg repos are owned by phpborg-borg, worker runs as phpborg)
        $escapedDir = escapeshellarg($dir);
        shell_exec("sudo rm -rf {$escapedDir} 2>&1");

        // Check if directory is gone
        return !is_dir($dir);
    }

    /**
     * Format bytes to human-readable format
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = floor(log($bytes) / log(1024));

        return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
    }
}
