<?php

declare(strict_types=1);

namespace PhpBorg\Service\Queue\Handlers;

use PhpBorg\Entity\Job;
use PhpBorg\Logger\LoggerInterface;
use PhpBorg\Repository\ArchiveRepository;
use PhpBorg\Repository\ArchiveMountRepository;
use PhpBorg\Repository\BorgRepositoryRepository;
use PhpBorg\Service\Backup\BorgExecutor;
use PhpBorg\Service\Queue\JobQueue;

/**
 * Handler for archive mount/unmount jobs
 * Responsibilities:
 * - Mount Borg archive to temporary directory
 * - Update mount status in database
 * - Create mount directory if needed
 */
final class ArchiveMountHandler implements JobHandlerInterface
{
    private const MOUNT_BASE_PATH = '/tmp/phpborg_mounts';

    public function __construct(
        private readonly BorgExecutor $borgExecutor,
        private readonly ArchiveRepository $archiveRepo,
        private readonly ArchiveMountRepository $mountRepo,
        private readonly BorgRepositoryRepository $repositoryRepo,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Handle archive mount job
     */
    public function handle(Job $job, JobQueue $queue): string
    {
        $payload = $job->payload;

        $archiveId = $payload['archive_id'] ?? null;
        $action = $payload['action'] ?? 'mount'; // mount or unmount

        if (!$archiveId) {
            throw new \Exception('Missing archive_id in job payload');
        }

        if ($action === 'mount') {
            return $this->handleMount($job, $queue, $archiveId);
        } elseif ($action === 'unmount') {
            return $this->handleUnmount($job, $queue, $archiveId);
        } else {
            throw new \Exception("Unknown action: {$action}");
        }
    }

    /**
     * Handle mount operation
     */
    private function handleMount(Job $job, JobQueue $queue, int $archiveId): string
    {
        $this->logger->info("Starting archive mount for ID: {$archiveId}", 'JOB');
        $queue->updateProgress($job->id, 10, "Preparing to mount archive...");

        try {
            // Step 1: Check if already mounted
            $existingMount = $this->mountRepo->findByArchiveId($archiveId);
            if ($existingMount && $existingMount->status === 'mounted') {
                $this->logger->info("Archive already mounted at: {$existingMount->mountPath}", 'JOB');
                $queue->updateProgress($job->id, 100, "Archive already mounted.");
                return "Archive already mounted at: {$existingMount->mountPath}";
            }

            // Step 2: Get archive info
            $queue->updateProgress($job->id, 20, "Loading archive information...");
            $archive = $this->archiveRepo->findById($archiveId);
            if (!$archive) {
                throw new \Exception("Archive #{$archiveId} not found");
            }

            // Step 3: Get repository info
            $queue->updateProgress($job->id, 30, "Loading repository configuration...");
            $repository = $this->repositoryRepo->findByRepoId($archive->repoId);
            if (!$repository) {
                throw new \Exception("Repository '{$archive->repoId}' not found");
            }

            // Step 4: Create mount directory
            $queue->updateProgress($job->id, 40, "Creating mount directory...");
            $mountPath = self::MOUNT_BASE_PATH . '/' . $archiveId;

            if (!is_dir(self::MOUNT_BASE_PATH)) {
                mkdir(self::MOUNT_BASE_PATH, 0755, true);
            }

            if (!is_dir($mountPath)) {
                mkdir($mountPath, 0755, true);
            }

            // Step 5: Create mount record
            $queue->updateProgress($job->id, 50, "Creating mount record...");
            if ($existingMount) {
                // Update existing record
                $this->mountRepo->updateStatus($existingMount->id, 'mounting');
                $mountId = $existingMount->id;
            } else {
                // Create new record
                $mountId = $this->mountRepo->create($archiveId, $mountPath, 'mounting');
            }

            // Step 6: All local repositories are owned by phpborg-borg, use sudo
            $runAsUser = 'phpborg-borg';

            // Step 7: Mount with Borg
            $queue->updateProgress($job->id, 60, "Mounting archive with Borg...");
            $archiveIdentifier = $repository->repoPath . '::' . $archive->name;

            $this->logger->info("Mounting archive '{$archiveIdentifier}' to '{$mountPath}'", 'JOB');

            try {
                $this->borgExecutor->mountArchive($archiveIdentifier, $mountPath, $repository->passphrase, $runAsUser);
            } catch (\Exception $borgError) {
                $this->logger->error("Borg mount error: {$borgError->getMessage()}", 'JOB');
                throw $borgError;
            }

            // Step 7: Update mount status
            $queue->updateProgress($job->id, 90, "Updating mount status...");
            $this->mountRepo->updateStatus($mountId, 'mounted');
            $this->logger->info("Archive mounted successfully at: {$mountPath}", 'JOB');

            $queue->updateProgress($job->id, 100, "Archive mounted successfully.");

            return "Archive '{$archive->name}' mounted at: {$mountPath}";

        } catch (\Exception $e) {
            $this->logger->error("Archive mount failed: {$e->getMessage()}", 'JOB');

            // Update mount status to error if record exists
            if (isset($mountId)) {
                try {
                    $this->mountRepo->updateStatus($mountId, 'error', $e->getMessage());
                } catch (\Exception $updateError) {
                    $this->logger->error("Failed to update mount status: {$updateError->getMessage()}", 'JOB');
                }
            }

            throw new \Exception("Archive mount failed: {$e->getMessage()}");
        }
    }

    /**
     * Handle unmount operation
     */
    private function handleUnmount(Job $job, JobQueue $queue, int $archiveId): string
    {
        $this->logger->info("Starting archive unmount for ID: {$archiveId}", 'JOB');
        $queue->updateProgress($job->id, 10, "Preparing to unmount archive...");

        try {
            // Step 1: Get mount info
            $queue->updateProgress($job->id, 20, "Loading mount information...");
            $mount = $this->mountRepo->findByArchiveId($archiveId);

            if (!$mount) {
                $this->logger->warning("No mount record found for archive #{$archiveId}", 'JOB');
                $queue->updateProgress($job->id, 100, "No mount found.");
                return "No mount record found for this archive.";
            }

            if ($mount->status !== 'mounted') {
                $this->logger->warning("Archive #{$archiveId} is not mounted (status: {$mount->status})", 'JOB');
                $queue->updateProgress($job->id, 100, "Archive not mounted.");
                return "Archive is not in mounted state.";
            }

            // Step 2: Update status to unmounting
            $queue->updateProgress($job->id, 30, "Updating mount status...");
            $this->mountRepo->updateStatus($mount->id, 'unmounting');

            // Step 3: Unmount with Borg
            $queue->updateProgress($job->id, 50, "Unmounting archive...");
            $this->logger->info("Unmounting archive from: {$mount->mountPath}", 'JOB');

            $this->borgExecutor->umountArchive($mount->mountPath);

            // Step 4: Remove mount directory
            $queue->updateProgress($job->id, 70, "Cleaning up mount directory...");
            if (is_dir($mount->mountPath)) {
                rmdir($mount->mountPath);
            }

            // Step 5: Delete mount record
            $queue->updateProgress($job->id, 90, "Removing mount record...");
            $this->mountRepo->deleteById($mount->id);
            $this->logger->info("Archive unmounted successfully: {$mount->mountPath}", 'JOB');

            $queue->updateProgress($job->id, 100, "Archive unmounted successfully.");

            return "Archive unmounted from: {$mount->mountPath}";

        } catch (\Exception $e) {
            $this->logger->error("Archive unmount failed: {$e->getMessage()}", 'JOB');

            // Update mount status to error if mount exists
            if (isset($mount)) {
                try {
                    $this->mountRepo->updateStatus($mount->id, 'error', $e->getMessage());
                } catch (\Exception $updateError) {
                    $this->logger->error("Failed to update mount status: {$updateError->getMessage()}", 'JOB');
                }
            }

            throw new \Exception("Archive unmount failed: {$e->getMessage()}");
        }
    }
}
