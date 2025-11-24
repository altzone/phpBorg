<?php

declare(strict_types=1);

namespace PhpBorg\Service\Queue\Handlers;

use PhpBorg\Entity\Job;
use PhpBorg\Exception\BackupException;
use PhpBorg\Logger\LoggerInterface;
use PhpBorg\Repository\PhpBorgBackupRepository;
use PhpBorg\Repository\SettingRepository;
use PhpBorg\Service\Queue\JobQueue;

/**
 * Handler for automatic cleanup of old phpBorg backups
 *
 * Features:
 * - Respects retention count from settings
 * - Deletes both file and database record
 * - Keeps most recent backups
 * - Logs all deletions
 * - Safe error handling (continues on individual failures)
 */
final class PhpBorgBackupCleanupHandler implements JobHandlerInterface
{
    public function __construct(
        private readonly PhpBorgBackupRepository $backupRepository,
        private readonly SettingRepository $settingRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    public function handle(Job $job, JobQueue $queue): string
    {
        $this->logger->info("Starting phpBorg backup cleanup");

        try {
            // Load retention count from settings
            $retentionCount = (int)($this->settingRepository->findByKey('backup_retention_count')?->value ?? '3');

            $this->logger->info("Retention policy: Keep {$retentionCount} most recent backups");

            // Find backups to delete (oldest beyond retention count)
            $oldBackups = $this->backupRepository->findOldestForCleanup($retentionCount);

            if (empty($oldBackups)) {
                $message = "No old backups to cleanup (retention: {$retentionCount})";
                $this->logger->info($message);
                return $message;
            }

            $this->logger->info("Found " . count($oldBackups) . " backups to cleanup");

            $deletedCount = 0;
            $errorCount = 0;
            $freedSpace = 0;

            foreach ($oldBackups as $backup) {
                try {
                    $sizeBytes = $backup->sizeBytes;

                    // Delete file from disk
                    if (file_exists($backup->filepath)) {
                        if (unlink($backup->filepath)) {
                            $freedSpace += $sizeBytes;
                            $this->logger->info("Deleted backup file: {$backup->filename}", [
                                'size' => $backup->getHumanSize(),
                                'created' => $backup->createdAt->format('Y-m-d H:i:s')
                            ]);
                        } else {
                            throw new BackupException("Failed to delete file: {$backup->filepath}");
                        }
                    } else {
                        $this->logger->warning("Backup file not found (will remove DB record): {$backup->filepath}");
                    }

                    // Delete database record
                    $this->backupRepository->delete($backup->id);
                    $this->logger->info("Deleted backup record from database: {$backup->filename}");

                    $deletedCount++;

                } catch (\Exception $e) {
                    $errorCount++;
                    $this->logger->error("Failed to delete backup: {$backup->filename}", [
                        'error' => $e->getMessage()
                    ]);
                    // Continue with next backup even if one fails
                }
            }

            $message = sprintf(
                "Cleanup completed: %d backups deleted, %d errors, %.2f MB freed",
                $deletedCount,
                $errorCount,
                $freedSpace / 1024 / 1024
            );

            $this->logger->info($message);

            return $message;

        } catch (\Exception $e) {
            $this->logger->error("Backup cleanup failed", [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}
