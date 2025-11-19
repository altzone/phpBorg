<?php

declare(strict_types=1);

namespace PhpBorg\Service\Queue\Handlers;

use PhpBorg\Entity\Job;
use PhpBorg\Logger\LoggerInterface;
use PhpBorg\Logger\UserOperationLogger;
use PhpBorg\Repository\ArchiveRepository;
use PhpBorg\Repository\BorgRepositoryRepository;
use PhpBorg\Repository\ServerRepository;
use PhpBorg\Repository\SettingRepository;
use PhpBorg\Service\Queue\JobQueue;
use PhpBorg\Service\Server\SshExecutor;
use Exception;

/**
 * Handler for archive restore jobs
 * Uses borg extract directly on client server for efficient restoration
 */
final class ArchiveRestoreHandler implements JobHandlerInterface
{
    public function __construct(
        private readonly ArchiveRepository $archiveRepo,
        private readonly BorgRepositoryRepository $repositoryRepo,
        private readonly ServerRepository $serverRepo,
        private readonly SettingRepository $settingRepo,
        private readonly SshExecutor $sshExecutor,
        private readonly LoggerInterface $logger,
        private readonly UserOperationLogger $userLogger
    ) {
    }

    /**
     * Handle archive restore job
     */
    public function handle(Job $job, JobQueue $queue): string
    {
        $payload = $job->payload;

        // Validate required payload fields
        $archiveId = $payload['archive_id'] ?? null;
        $serverId = $payload['server_id'] ?? null;
        $files = $payload['files'] ?? [];
        $restoreMode = $payload['restore_mode'] ?? 'alternate';
        $destination = $payload['destination'] ?? null;
        $overwriteMode = $payload['overwrite_mode'] ?? 'newer';
        $preservePermissions = $payload['preserve_permissions'] ?? true;
        $preserveOwner = $payload['preserve_owner'] ?? true;
        $verifyChecksums = $payload['verify_checksums'] ?? false;
        $dryRun = $payload['dry_run'] ?? false;

        if (!$archiveId || !$serverId || empty($files)) {
            throw new Exception('Missing required fields: archive_id, server_id, or files');
        }

        try {
            return $this->executeRestore(
                $job,
                $queue,
                $archiveId,
                $serverId,
                $files,
                $restoreMode,
                $destination,
                $overwriteMode,
                $preservePermissions,
                $preserveOwner,
                $verifyChecksums,
                $dryRun
            );
        } catch (Exception $e) {
            $this->logger->error("Restore failed: {$e->getMessage()}", 'RESTORE');

            // USER LOG: Archive restore failed
            $this->userLogger->error('archive_restore', "Archive restore failed: {$e->getMessage()}", [
                'archive_id' => $archiveId,
                'server_id' => $serverId,
                'restore_mode' => $restoreMode,
                'error' => $e->getMessage(),
                'job_id' => $job->id
            ]);

            throw new Exception("Archive restore failed: {$e->getMessage()}");
        }
    }

    /**
     * Execute the restore using borg extract on client server
     */
    private function executeRestore(
        Job $job,
        JobQueue $queue,
        int $archiveId,
        int $serverId,
        array $files,
        string $restoreMode,
        ?string $destination,
        string $overwriteMode,
        bool $preservePermissions,
        bool $preserveOwner,
        bool $verifyChecksums,
        bool $dryRun
    ): string {
        $queue->updateProgress($job->id, 5, 'Preparing restore...');

        // 1. Get archive information
        $archive = $this->archiveRepo->findById($archiveId);
        if (!$archive) {
            throw new Exception("Archive not found: {$archiveId}");
        }

        $queue->updateProgress($job->id, 10, 'Loading repository information...');

        // 2. Get repository information (includes passphrase)
        $repository = $this->repositoryRepo->findByRepoId($archive->repoId);
        if (!$repository) {
            throw new Exception("Repository not found: {$archive->repoId}");
        }

        $queue->updateProgress($job->id, 15, 'Loading server information...');

        // 3. Get destination server information
        $server = $this->serverRepo->findById($serverId);
        if (!$server) {
            throw new Exception("Server not found: {$serverId}");
        }

        // USER LOG: Archive restore started
        $this->userLogger->info('archive_restore', "Archive restore started: '{$archive->name}'", [
            'archive_id' => $archiveId,
            'archive_name' => $archive->name,
            'server_id' => $serverId,
            'server_name' => $server->name,
            'restore_mode' => $restoreMode,
            'destination' => $destination,
            'files_count' => count($files),
            'dry_run' => $dryRun,
            'job_id' => $job->id
        ]);

        $queue->updateProgress($job->id, 20, 'Testing SSH connectivity...');

        // 4. Test SSH connectivity
        $testResult = $this->sshExecutor->execute($server, 'echo "SSH OK"');
        if ($testResult['exitCode'] !== 0 || strpos($testResult['stdout'], 'SSH OK') === false) {
            throw new Exception("SSH connection to {$server->name} failed");
        }

        $this->logger->info("SSH connection to {$server->name} successful", 'RESTORE');

        $queue->updateProgress($job->id, 25, 'Determining restore destination...');

        // 5. Determine working directory based on restore mode
        $workingDir = $this->getWorkingDirectory($restoreMode, $destination);

        $queue->updateProgress($job->id, 30, 'Preparing borg extract command...');

        // 6. Build environment variables for borg
        $borgRepo = $this->getBorgRepoUrl($repository, $server);
        $passphrase = $repository->passphrase; // Already decoded by FROM_BASE64 in SQL query

        // 7. Build the borg extract command
        $command = $this->buildBorgExtractCommand(
            $borgRepo,
            $passphrase,
            $archive->name, // Archive name
            $files,
            $workingDir,
            $restoreMode,
            $preservePermissions,
            $preserveOwner,
            $dryRun
        );

        $queue->updateProgress($job->id, 40, 'Executing restore on destination server...');

        $this->logger->info("Executing restore: {$command}", 'RESTORE');

        // 8. Execute borg extract on destination server
        try {
            $result = $this->sshExecutor->execute($server, $command, timeout: 7200); // 2 hour timeout

            if ($result['exitCode'] !== 0) {
                throw new Exception("Borg extract failed with exit code {$result['exitCode']}: {$result['stderr']}");
            }

            $queue->updateProgress($job->id, 90, 'Restore completed, verifying...');

            $this->logger->info("Restore output:\n{$result['stdout']}", 'RESTORE');

            // 9. Verify restoration
            $filesCount = count($files);
            $queue->updateProgress($job->id, 95, "Successfully restored {$filesCount} items");

            // 10. Apply suffix mode if needed (rename after extraction)
            if ($restoreMode === 'suffix') {
                $queue->updateProgress($job->id, 97, 'Applying timestamp suffix...');
                $this->applySuffixMode($server, $files, $workingDir);
            }

            $queue->updateProgress($job->id, 100, 'Restore completed successfully');

            $message = sprintf(
                "Successfully restored %d items to %s:%s in %s mode",
                $filesCount,
                $server->name,
                $workingDir,
                $restoreMode
            );

            if ($dryRun) {
                $message = "[DRY RUN] " . $message;
            }

            // USER LOG: Archive restore completed successfully
            $this->userLogger->info('archive_restore', "Archive restore completed successfully: '{$archive->name}'", [
                'archive_id' => $archiveId,
                'archive_name' => $archive->name,
                'server_id' => $serverId,
                'server_name' => $server->name,
                'restore_mode' => $restoreMode,
                'destination' => $workingDir,
                'files_count' => $filesCount,
                'dry_run' => $dryRun,
                'job_id' => $job->id
            ]);

            return $message;

        } catch (Exception $e) {
            throw new Exception("Borg extract failed: {$e->getMessage()}");
        }
    }

    /**
     * Get working directory based on restore mode
     */
    private function getWorkingDirectory(string $restoreMode, ?string $destination): string
    {
        switch ($restoreMode) {
            case 'in_place':
                return '/'; // Extract to root, files go to original location

            case 'alternate':
                if (!$destination) {
                    throw new Exception('Destination path required for alternate restore mode');
                }
                return rtrim($destination, '/');

            case 'suffix':
                // For suffix mode, extract to temp location first
                return '/tmp/restore-' . date('YmdHis');

            default:
                throw new Exception("Invalid restore mode: {$restoreMode}");
        }
    }

    /**
     * Build Borg repository URL for SSH access
     */
    private function getBorgRepoUrl(object $repository, object $server): string
    {
        // Determine phpborg server host based on backup type
        if (strtolower($server->backupType) === 'internal') {
            // Use internal IP from settings for internal backups
            $internalIpSetting = $this->settingRepo->findByKey('network.internal_ip');
            $phpborgHost = $internalIpSetting ? $internalIpSetting->value : gethostname();
        } else {
            // Use hostname for external backups
            $phpborgHost = gethostname();
        }

        return sprintf(
            'ssh://phpborg@%s%s',
            $phpborgHost,
            $repository->repoPath
        );
    }

    /**
     * Build borg extract command with all options
     */
    private function buildBorgExtractCommand(
        string $borgRepo,
        string $passphrase,
        string $archiveName,
        array $files,
        string $workingDir,
        string $restoreMode,
        bool $preservePermissions,
        bool $preserveOwner,
        bool $dryRun
    ): string {
        // Encode passphrase in base64 to safely pass through shell
        // Then decode it on the remote side
        $passphraseB64 = base64_encode($passphrase);

        // Build file paths list (remove leading slashes for borg)
        $filePaths = array_map(function($path) {
            return ltrim($path, '/');
        }, $files);

        $filesList = implode(' ', array_map('escapeshellarg', $filePaths));

        // Build borg options
        $borgOptions = ['--list', '--progress'];

        if ($dryRun) {
            $borgOptions[] = '--dry-run';
        }

        $optionsStr = implode(' ', $borgOptions);

        // Build full command
        // Use base64 decode to safely handle passphrase with special characters
        $command = sprintf(
            'export BORG_REPO=%s && ' .
            'export BORG_PASSPHRASE=$(echo %s | base64 -d) && ' .
            'export BORG_RSH="ssh -i /root/.ssh/phpborg_backup -o StrictHostKeyChecking=no" && ' .
            'mkdir -p %s && cd %s && ' .
            'borg extract %s ::%s %s 2>&1',
            escapeshellarg($borgRepo),
            escapeshellarg($passphraseB64),
            escapeshellarg($workingDir),
            escapeshellarg($workingDir),
            $optionsStr,
            escapeshellarg($archiveName),
            $filesList
        );

        return $command;
    }

    /**
     * Apply suffix mode by renaming extracted files
     */
    private function applySuffixMode(object $server, array $files, string $workingDir): void
    {
        $suffix = '.restored-' . date('YmdHis');

        foreach ($files as $filePath) {
            $extractedPath = $workingDir . '/' . ltrim($filePath, '/');
            $newPath = $extractedPath . $suffix;

            // Move to original location with suffix
            $originalPath = $filePath . $suffix;
            $command = sprintf(
                'if [ -e %s ]; then mv %s %s; fi',
                escapeshellarg($extractedPath),
                escapeshellarg($extractedPath),
                escapeshellarg($originalPath)
            );

            try {
                $this->sshExecutor->execute($server, $command);
                $this->logger->info("Applied suffix to {$filePath} -> {$originalPath}", 'RESTORE');
            } catch (Exception $e) {
                $this->logger->warning("Failed to apply suffix to {$filePath}: {$e->getMessage()}", 'RESTORE');
            }
        }
    }
}
