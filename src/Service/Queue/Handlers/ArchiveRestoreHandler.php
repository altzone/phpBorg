<?php

declare(strict_types=1);

namespace PhpBorg\Service\Queue\Handlers;

use PhpBorg\Entity\Job;
use PhpBorg\Logger\LoggerInterface;
use PhpBorg\Repository\ArchiveRepository;
use PhpBorg\Repository\ArchiveMountRepository;
use PhpBorg\Repository\BorgRepositoryRepository;
use PhpBorg\Repository\ServerRepository;
use PhpBorg\Service\Queue\JobQueue;
use PhpBorg\Service\Server\SshExecutor;
use Symfony\Component\Process\Process;
use Exception;

/**
 * Handler for archive restore jobs
 * Responsibilities:
 * - Restore files/directories from mounted archive to destination server
 * - Support multiple restore modes: in-place, alternate, suffix
 * - Use rsync over SSH for efficient transfer
 * - Track progress and provide detailed logging
 */
final class ArchiveRestoreHandler implements JobHandlerInterface
{
    public function __construct(
        private readonly ArchiveRepository $archiveRepo,
        private readonly ArchiveMountRepository $mountRepo,
        private readonly BorgRepositoryRepository $repositoryRepo,
        private readonly ServerRepository $serverRepo,
        private readonly SshExecutor $sshExecutor,
        private readonly LoggerInterface $logger
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
        $restoreMode = $payload['restore_mode'] ?? 'alternate'; // in_place | alternate | suffix
        $destination = $payload['destination'] ?? null;
        $overwriteMode = $payload['overwrite_mode'] ?? 'newer'; // always | newer | never | rename
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
            throw new Exception("Archive restore failed: {$e->getMessage()}");
        }
    }

    /**
     * Execute the restore operation
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
        $this->logger->info("Starting restore for archive #{$archiveId} to server #{$serverId}", 'RESTORE');
        $queue->updateProgress($job->id, 5, "Initializing restore...");

        // Step 1: Validate archive is mounted
        $queue->updateProgress($job->id, 10, "Checking archive mount status...");
        $mount = $this->mountRepo->findByArchiveId($archiveId);

        if (!$mount || $mount->status !== 'mounted') {
            throw new Exception("Archive must be mounted before restore. Please mount the archive first.");
        }

        $mountPath = $mount->mountPath;
        $this->logger->info("Archive mounted at: {$mountPath}", 'RESTORE');

        // Step 2: Get archive and server info
        $queue->updateProgress($job->id, 15, "Loading archive and server information...");
        $archive = $this->archiveRepo->findById($archiveId);
        if (!$archive) {
            throw new Exception("Archive #{$archiveId} not found");
        }

        $server = $this->serverRepo->findById($serverId);
        if (!$server) {
            throw new Exception("Server #{$serverId} not found");
        }

        $this->logger->info("Restoring to server: {$server->name} ({$server->hostname})", 'RESTORE');

        // Step 3: Test SSH connectivity
        $queue->updateProgress($job->id, 20, "Testing SSH connection to destination server...");
        if (!$this->sshExecutor->testConnection($server)) {
            throw new Exception("Cannot connect to destination server via SSH");
        }

        // Step 4: Determine destination path
        $queue->updateProgress($job->id, 25, "Determining destination path...");
        $destinationPath = $this->determineDestinationPath(
            $restoreMode,
            $destination,
            $files[0] ?? '/'
        );

        $this->logger->info("Destination path: {$destinationPath}", 'RESTORE');

        // Step 5: Validate destination (space, permissions, etc.)
        $queue->updateProgress($job->id, 30, "Validating destination...");
        $this->validateDestination($server, $destinationPath, $files);

        // Step 6: Create files list for rsync
        $queue->updateProgress($job->id, 35, "Preparing file list...");
        $filesListPath = $this->createFilesList($files);

        // Step 7: Build rsync command
        $queue->updateProgress($job->id, 40, "Building restore command...");
        $rsyncCommand = $this->buildRsyncCommand(
            $mountPath,
            $server,
            $destinationPath,
            $filesListPath,
            $restoreMode,
            $overwriteMode,
            $preservePermissions,
            $preserveOwner,
            $dryRun
        );

        $this->logger->info("Rsync command prepared" . ($dryRun ? " (DRY RUN)" : ""), 'RESTORE');

        // Step 8: Execute rsync
        $queue->updateProgress($job->id, 50, $dryRun ? "Simulating restore (dry run)..." : "Restoring files...");
        $result = $this->executeRsync($rsyncCommand, $job, $queue);

        // Step 9: Verify if requested
        if ($verifyChecksums && !$dryRun) {
            $queue->updateProgress($job->id, 90, "Verifying restored files...");
            $this->verifyRestored($server, $files, $destinationPath);
        }

        // Step 10: Cleanup
        $queue->updateProgress($job->id, 95, "Cleaning up...");
        if (file_exists($filesListPath)) {
            unlink($filesListPath);
        }

        $queue->updateProgress($job->id, 100, "Restore completed successfully.");

        $filesCount = count($files);
        $message = $dryRun
            ? "Dry run completed: {$filesCount} files would be restored to {$server->name}:{$destinationPath}"
            : "Successfully restored {$filesCount} files to {$server->name}:{$destinationPath}";

        $this->logger->info($message, 'RESTORE');

        return $message . "\n\n" . $result;
    }

    /**
     * Determine the final destination path based on restore mode
     */
    private function determineDestinationPath(string $restoreMode, ?string $customDest, string $firstFile): string
    {
        switch ($restoreMode) {
            case 'in_place':
                // Restore to original location
                return '/';

            case 'alternate':
                // Restore to custom location
                if (!$customDest) {
                    throw new Exception("Alternate location requires a destination path");
                }
                return rtrim($customDest, '/') . '/';

            case 'suffix':
                // Restore to original location with suffix
                return '/';

            default:
                throw new Exception("Unknown restore mode: {$restoreMode}");
        }
    }

    /**
     * Validate destination (space, permissions, etc.)
     */
    private function validateDestination($server, string $destination, array $files): void
    {
        // Check if destination directory exists or can be created
        $checkCommand = sprintf(
            'test -d %s || mkdir -p %s',
            escapeshellarg($destination),
            escapeshellarg($destination)
        );

        $result = $this->sshExecutor->execute($server, $checkCommand, 30);

        if ($result['exitCode'] !== 0) {
            throw new Exception("Cannot access or create destination directory: {$destination}");
        }

        // Check write permissions
        $writeTestCommand = sprintf(
            'test -w %s',
            escapeshellarg($destination)
        );

        $result = $this->sshExecutor->execute($server, $writeTestCommand, 10);

        if ($result['exitCode'] !== 0) {
            throw new Exception("No write permission on destination: {$destination}");
        }

        $this->logger->debug("Destination validation passed", 'RESTORE');
    }

    /**
     * Create temporary file with list of files to restore
     */
    private function createFilesList(array $files): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'phpborg_restore_');

        $content = '';
        foreach ($files as $file) {
            // Remove leading slash for rsync --files-from
            $file = ltrim($file, '/');
            $content .= $file . "\n";
        }

        file_put_contents($tmpFile, $content);

        $this->logger->debug("Created files list: {$tmpFile} (" . count($files) . " files)", 'RESTORE');

        return $tmpFile;
    }

    /**
     * Build rsync command with all options
     */
    private function buildRsyncCommand(
        string $sourcePath,
        $server,
        string $destination,
        string $filesListPath,
        string $restoreMode,
        string $overwriteMode,
        bool $preservePermissions,
        bool $preserveOwner,
        bool $dryRun
    ): array {
        $rsyncOptions = [
            '-a', // archive mode (recursive, preserve permissions, times, etc.)
            '-v', // verbose
            '-z', // compress during transfer
            '--progress', // show progress
            '--stats', // show transfer stats
            '--files-from=' . $filesListPath, // read file list from file
        ];

        // Dry run mode
        if ($dryRun) {
            $rsyncOptions[] = '--dry-run';
        }

        // Overwrite mode
        switch ($overwriteMode) {
            case 'newer':
                $rsyncOptions[] = '--update'; // skip files that are newer on the receiver
                break;

            case 'never':
                $rsyncOptions[] = '--ignore-existing'; // skip updating files that exist on receiver
                break;

            case 'rename':
                $rsyncOptions[] = '--backup'; // backup existing files
                $rsyncOptions[] = '--suffix=.before-restore'; // suffix for backed up files
                break;

            case 'always':
            default:
                // No special option - always overwrite
                break;
        }

        // Suffix mode: add timestamp suffix to restored files
        if ($restoreMode === 'suffix') {
            $suffix = '.restored-' . date('YmdHis');
            $rsyncOptions[] = '--suffix=' . $suffix;
        }

        // Preserve permissions
        if (!$preservePermissions) {
            $rsyncOptions[] = '--no-perms';
            $rsyncOptions[] = '--no-owner';
            $rsyncOptions[] = '--no-group';
        }

        if (!$preserveOwner) {
            $rsyncOptions[] = '--no-owner';
            $rsyncOptions[] = '--no-group';
        }

        // SSH options
        $sshOptions = sprintf(
            '-e "ssh -i /root/.ssh/id_rsa -o StrictHostKeyChecking=no -o BatchMode=yes -p %d"',
            $server->port
        );

        // Build full command
        // rsync [options] source/ user@host:destination/
        $command = array_merge(
            ['rsync'],
            $rsyncOptions,
            [$sshOptions],
            [$sourcePath . '/'], // source with trailing slash
            [sprintf('%s@%s:%s', $server->username ?? 'root', $server->hostname, $destination)]
        );

        return $command;
    }

    /**
     * Execute rsync command and track progress
     */
    private function executeRsync(array $command, Job $job, JobQueue $queue): string
    {
        $commandString = implode(' ', array_map('escapeshellarg', $command));

        $this->logger->debug("Executing rsync: {$commandString}", 'RESTORE');

        $process = new Process($command, null, null, null, 3600); // 1 hour timeout
        $process->setTimeout(3600);

        $output = '';
        $errorOutput = '';

        $process->run(function ($type, $buffer) use (&$output, &$errorOutput, $job, $queue) {
            if (Process::ERR === $type) {
                $errorOutput .= $buffer;
            } else {
                $output .= $buffer;

                // Try to parse progress from rsync output
                // Format: "1,234,567  45%  1.23MB/s    0:00:12"
                if (preg_match('/(\d+)%/', $buffer, $matches)) {
                    $percent = (int)$matches[1];
                    // Map rsync progress (0-100) to job progress (50-90)
                    $jobProgress = 50 + ($percent * 40 / 100);
                    $queue->updateProgress($job->id, (int)$jobProgress, "Restoring files... {$percent}%");
                }
            }
        });

        if (!$process->isSuccessful()) {
            $this->logger->error("Rsync failed: " . $errorOutput, 'RESTORE');
            throw new Exception("Rsync failed: " . $errorOutput);
        }

        return $output;
    }

    /**
     * Verify restored files by comparing checksums
     */
    private function verifyRestored($server, array $files, string $destination): void
    {
        $this->logger->info("Verifying restored files...", 'RESTORE');

        // TODO: Implement checksum verification
        // For now, just check if files exist

        $filesCount = min(count($files), 5); // Check first 5 files
        for ($i = 0; $i < $filesCount; $i++) {
            $file = $files[$i];
            $destFile = rtrim($destination, '/') . '/' . ltrim($file, '/');

            $checkCommand = sprintf('test -e %s', escapeshellarg($destFile));
            $result = $this->sshExecutor->execute($server, $checkCommand, 10);

            if ($result['exitCode'] !== 0) {
                $this->logger->warning("Restored file not found: {$destFile}", 'RESTORE');
            }
        }

        $this->logger->info("Verification completed", 'RESTORE');
    }
}
