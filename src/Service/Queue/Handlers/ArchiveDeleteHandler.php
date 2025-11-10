<?php

declare(strict_types=1);

namespace PhpBorg\Service\Queue\Handlers;

use PhpBorg\Entity\Job;
use PhpBorg\Logger\LoggerInterface;
use PhpBorg\Repository\ArchiveRepository;
use PhpBorg\Repository\BorgRepositoryRepository;
use PhpBorg\Service\Backup\BorgExecutor;
use PhpBorg\Service\Queue\JobQueue;

/**
 * Handler for archive deletion jobs
 * Responsibilities:
 * - Delete specific archive using Borg
 * - Remove archive from database if successful
 * - Update repository statistics
 */
final class ArchiveDeleteHandler implements JobHandlerInterface
{
    public function __construct(
        private readonly BorgExecutor $borgExecutor,
        private readonly ArchiveRepository $archiveRepo,
        private readonly BorgRepositoryRepository $repositoryRepo,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Handle archive deletion job
     */
    public function handle(Job $job, JobQueue $queue): string
    {
        $payload = $job->payload;

        $archiveId = $payload['archive_id'] ?? null;
        $archiveName = $payload['archive_name'] ?? null;
        $userId = $payload['user_id'] ?? null;

        if (!$archiveId) {
            throw new \Exception('Missing archive_id in job payload');
        }

        $this->logger->info("Starting archive deletion for ID: {$archiveId}", 'JOB');
        $queue->updateProgress($job->id, 10, "Preparing to delete archive...");

        try {
            // Step 1: Get archive info
            $queue->updateProgress($job->id, 20, "Loading archive information...");
            $archive = $this->archiveRepo->findById($archiveId);
            if (!$archive) {
                throw new \Exception("Archive #{$archiveId} not found");
            }

            // Check if archive has a valid name
            if (empty($archive->name)) {
                $this->logger->warning("Archive #{$archiveId} has empty name, removing from database only", 'JOB');
                $queue->updateProgress($job->id, 80, "Removing corrupted archive from database...");
                $this->archiveRepo->deleteById($archiveId);
                $queue->updateProgress($job->id, 100, "Corrupted archive removed from database.");
                return "Corrupted archive (empty name) removed from database. No Borg operation needed.";
            }

            // Step 2: Get repository info
            $queue->updateProgress($job->id, 30, "Loading repository configuration...");
            $repository = $this->repositoryRepo->findByRepoId($archive->repoId);
            if (!$repository) {
                throw new \Exception("Repository '{$archive->repoId}' not found");
            }

            $this->logger->info("Deleting archive '{$archive->name}' from repository '{$repository->repoPath}'", 'JOB');

            // Step 3: Delete from Borg
            $queue->updateProgress($job->id, 50, "Deleting archive from Borg repository...");
            $archiveIdentifier = $repository->repoPath . '::' . $archive->name;
            
            $result = $this->borgExecutor->deleteArchive($archiveIdentifier, $repository->passphrase);
            
            $this->logger->info("Archive deleted from Borg successfully", 'JOB');
            
            // Log Borg output
            if (!empty($result['stderr'])) {
                foreach (explode("\n", trim($result['stderr'])) as $line) {
                    if (!empty($line)) {
                        $this->logger->info("[BORG DELETE] {$line}", 'JOB');
                    }
                }
            }

            // Step 4: Remove from database
            $queue->updateProgress($job->id, 80, "Updating database...");
            $this->archiveRepo->deleteById($archiveId);
            $this->logger->info("Archive removed from database", 'JOB');

            // Step 5: Update repository stats (optional - could be done later)
            $queue->updateProgress($job->id, 95, "Updating repository statistics...");
            try {
                // Get updated repository info after deletion
                $repoInfo = $this->borgExecutor->getRepositoryInfo($repository->repoPath, $repository->passphrase);
                $cacheStats = $repoInfo['cache']['stats'] ?? [];

                $this->repositoryRepo->updateStatistics(
                    repoId: $repository->repoId,
                    size: (int)($cacheStats['total_size'] ?? 0),
                    compressedSize: (int)($cacheStats['total_csize'] ?? 0),
                    deduplicatedSize: (int)($cacheStats['unique_csize'] ?? 0),
                    totalUniqueChunks: (int)($cacheStats['total_unique_chunks'] ?? 0),
                    totalChunks: (int)($cacheStats['total_chunks'] ?? 0)
                );
                
                $this->logger->info("Repository statistics updated", 'JOB');
            } catch (\Exception $e) {
                $this->logger->warning("Failed to update repository statistics: {$e->getMessage()}", 'JOB');
                // Don't fail the job for this
            }

            $queue->updateProgress($job->id, 100, "Archive deletion completed successfully.");
            
            return "Archive '{$archive->name}' deleted successfully from repository '{$repository->repoPath}'";

        } catch (\Exception $e) {
            $this->logger->error("Archive deletion failed: {$e->getMessage()}", 'JOB');
            throw new \Exception("Archive deletion failed: {$e->getMessage()}");
        }
    }
}