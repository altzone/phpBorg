<?php

declare(strict_types=1);

namespace PhpBorg\Service\Backup;

use PhpBorg\Config\Configuration;
use PhpBorg\Exception\BackupException;
use PhpBorg\Logger\LoggerInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Execute Borg backup commands securely
 */
final class BorgExecutor
{
    public function __construct(
        private readonly Configuration $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Execute borg command with passphrase
     *
     * @param array<int, string> $arguments
     * @param string|null $runAsUser Optional user to run as (uses sudo -u)
     * @return array{stdout: string, stderr: string, exitCode: int}
     * @throws BackupException
     */
    public function execute(array $arguments, string $passphrase, int $timeout = 3600, ?string $runAsUser = null): array
    {
        if ($runAsUser !== null) {
            // Use sudo -u to run as different user
            // sudo -u phpborg-borg env BORG_PASSPHRASE=xxx borg ...
            $command = array_merge(
                ['sudo', '-u', $runAsUser, 'env', "BORG_PASSPHRASE={$passphrase}"],
                [$this->config->borgBinaryPath],
                $arguments
            );
            $env = [
                'BORG_RELOCATED_REPO_ACCESS_IS_OK' => 'yes',
                'BORG_UNKNOWN_UNENCRYPTED_REPO_ACCESS_IS_OK' => 'no',
            ];
        } else {
            $command = array_merge([$this->config->borgBinaryPath], $arguments);
            $env = [
                'BORG_PASSPHRASE' => $passphrase,
                'BORG_RELOCATED_REPO_ACCESS_IS_OK' => 'yes',
                'BORG_UNKNOWN_UNENCRYPTED_REPO_ACCESS_IS_OK' => 'no',
            ];
        }

        $process = new Process($command, null, $env, null, $timeout);

        $this->logger->debug('Executing Borg command: ' . $process->getCommandLine(), 'BORG');

        try {
            $process->run();
        } catch (ProcessFailedException $e) {
            $this->logger->error(
                'Borg command failed: ' . $e->getMessage(),
                'BORG',
                ['command' => $arguments[0] ?? 'unknown']
            );
            throw new BackupException('Borg command failed: ' . $e->getMessage(), 0, $e);
        }

        return [
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
            'exitCode' => $process->getExitCode() ?? 1,
        ];
    }

    /**
     * Initialize a new Borg repository
     *
     * @throws BackupException
     */
    public function initRepository(string $path, string $passphrase, string $encryption = 'repokey'): void
    {
        $result = $this->execute(
            ['init', '--encryption', $encryption, $path],
            $passphrase,
            300
        );

        if ($result['exitCode'] !== 0) {
            throw new BackupException("Failed to initialize repository: {$result['stderr']}");
        }

        $this->logger->info("Borg repository initialized: {$path}", 'BORG');
    }

    /**
     * Create a backup archive
     *
     * @param array<int, string> $paths
     * @param array<int, string> $excludePatterns
     * @return array{stdout: string, stderr: string, exitCode: int}
     * @throws BackupException
     */
    public function createArchive(
        string $repository,
        string $archiveName,
        array $paths,
        string $passphrase,
        string $compression = 'lz4',
        array $excludePatterns = [],
        int $rateLimit = 0
    ): array {
        $arguments = [
            'create',
            '--stats',
            '--json',
            '--compression', $compression,
        ];

        if ($rateLimit > 0) {
            $arguments[] = '--remote-ratelimit';
            $arguments[] = (string)$rateLimit;
        }

        foreach ($excludePatterns as $pattern) {
            $arguments[] = '--exclude';
            $arguments[] = $pattern;
        }

        $arguments[] = "{$repository}::{$archiveName}";
        $arguments = array_merge($arguments, $paths);

        return $this->execute($arguments, $passphrase, 7200);
    }

    /**
     * Create a backup archive with real-time progress updates
     *
     * @param array<int, string> $paths
     * @param array<int, string> $excludePatterns
     * @param callable|null $progressCallback Callback function(int $percent, string $message): void
     * @return array{stdout: string, stderr: string, exitCode: int}
     * @throws BackupException
     */
    public function createArchiveWithProgress(
        string $repository,
        string $archiveName,
        array $paths,
        string $passphrase,
        string $compression = 'lz4',
        array $excludePatterns = [],
        int $rateLimit = 0,
        ?callable $progressCallback = null
    ): array {
        $arguments = [
            'create',
            '--stats',
            '--json',
            '--progress',  // Enable real-time progress
            '--compression', $compression,
        ];

        if ($rateLimit > 0) {
            $arguments[] = '--remote-ratelimit';
            $arguments[] = (string)$rateLimit;
        }

        foreach ($excludePatterns as $pattern) {
            $arguments[] = '--exclude';
            $arguments[] = $pattern;
        }

        $arguments[] = "{$repository}::{$archiveName}";
        $arguments = array_merge($arguments, $paths);

        // Execute with streaming progress
        return $this->executeWithProgress($arguments, $passphrase, 7200, $progressCallback);
    }

    /**
     * Execute borg command with progress streaming
     *
     * @param array<int, string> $arguments
     * @param callable|null $progressCallback
     * @return array{stdout: string, stderr: string, exitCode: int}
     * @throws BackupException
     */
    private function executeWithProgress(array $arguments, string $passphrase, int $timeout, ?callable $progressCallback): array
    {
        $command = array_merge([$this->config->borgBinaryPath], $arguments);

        $env = [
            'BORG_PASSPHRASE' => $passphrase,
            'BORG_RELOCATED_REPO_ACCESS_IS_OK' => 'yes',
            'BORG_UNKNOWN_UNENCRYPTED_REPO_ACCESS_IS_OK' => 'no',
        ];

        $process = new Process($command, null, $env, null, $timeout);

        $this->logger->debug('Executing Borg command with progress: ' . $process->getCommandLine(), 'BORG');

        $stdout = '';
        $stderr = '';
        $lastProgressTime = 0;

        try {
            $process->start();

            // Stream output in real-time
            while ($process->isRunning()) {
                // Borg outputs progress JSON to stderr
                $newStderr = $process->getIncrementalErrorOutput();
                if (!empty($newStderr)) {
                    $stderr .= $newStderr;

                    // Parse progress JSON (throttle to max 1 update per second)
                    if ($progressCallback && (time() - $lastProgressTime) >= 1) {
                        $this->parseProgressAndCallback($newStderr, $progressCallback);
                        $lastProgressTime = time();
                    }
                }

                // Collect stdout
                $stdout .= $process->getIncrementalOutput();

                usleep(100000); // Sleep 100ms between checks
            }

            // Get final output
            $stdout .= $process->getOutput();
            $stderr .= $process->getErrorOutput();

        } catch (ProcessFailedException $e) {
            $this->logger->error(
                'Borg command failed: ' . $e->getMessage(),
                'BORG',
                ['command' => $arguments[0] ?? 'unknown']
            );
            throw new BackupException('Borg command failed: ' . $e->getMessage(), 0, $e);
        }

        return [
            'stdout' => $stdout,
            'stderr' => $stderr,
            'exitCode' => $process->getExitCode() ?? 1,
        ];
    }

    /**
     * Parse progress JSON from Borg and call callback
     */
    private function parseProgressAndCallback(string $output, callable $callback): void
    {
        // Borg outputs JSON lines for progress
        $lines = explode("\n", $output);

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            $data = json_decode($line, true);
            if (!is_array($data) || !isset($data['type'])) {
                continue;
            }

            // Handle archive_progress messages
            if ($data['type'] === 'archive_progress') {
                $originalSize = $data['original_size'] ?? 0;
                $nfiles = $data['nfiles'] ?? 0;
                $path = $data['path'] ?? '';

                // Calculate rough progress based on file count
                // (Borg doesn't give us total files, so we use heuristics)
                // We'll just update the UI with current stats
                $message = sprintf(
                    "Processing: %s (%d files, %s)",
                    basename($path),
                    $nfiles,
                    $this->formatBytes($originalSize)
                );

                // Call callback with progress info (we can't calculate exact %, so use file count as indicator)
                $callback(0, $message); // Progress percentage unknown
            }
            // Handle progress_percent messages (if Borg adds them in future)
            elseif ($data['type'] === 'progress_percent') {
                $percent = (int)($data['current'] ?? 0);
                $message = $data['message'] ?? 'Processing...';
                $callback($percent, $message);
            }
        }
    }

    /**
     * Format bytes to human-readable size
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Get repository info as JSON
     *
     * @return array<string, mixed>
     * @throws BackupException
     */
    public function getRepositoryInfo(string $repository, string $passphrase): array
    {
        $result = $this->execute(
            ['info', '--json', $repository],
            $passphrase,
            60
        );

        if ($result['exitCode'] !== 0) {
            throw new BackupException("Failed to get repository info: {$result['stderr']}");
        }

        $data = json_decode($result['stdout'], true);
        if (!is_array($data)) {
            throw new BackupException('Invalid JSON response from Borg');
        }

        return $data;
    }

    /**
     * List archives in a repository
     *
     * @return array<int, array{name: string, id: string, start: string, time: string}>
     * @throws BackupException
     */
    public function listArchives(string $repository, string $passphrase, ?string $runAsUser = null): array
    {
        $result = $this->execute(
            ['list', '--json', $repository],
            $passphrase,
            60,
            $runAsUser
        );

        if ($result['exitCode'] !== 0) {
            throw new BackupException("Failed to list archives: {$result['stderr']}");
        }

        $data = json_decode($result['stdout'], true);
        if (!is_array($data)) {
            throw new BackupException('Invalid JSON response from Borg');
        }

        // borg list --json returns {archives: [...]}
        return $data['archives'] ?? [];
    }

    /**
     * Get archive info as JSON
     *
     * @return array<string, mixed>
     * @throws BackupException
     */
    public function getArchiveInfo(string $archive, string $passphrase, ?string $runAsUser = null): array
    {
        $result = $this->execute(
            ['info', '--json', $archive],
            $passphrase,
            60,
            $runAsUser
        );

        if ($result['exitCode'] !== 0) {
            throw new BackupException("Failed to get archive info: {$result['stderr']}");
        }

        $data = json_decode($result['stdout'], true);
        if (!is_array($data)) {
            throw new BackupException('Invalid JSON response from Borg');
        }

        return $data;
    }

    /**
     * Prune old archives according to retention policy
     *
     * @return array{stdout: string, stderr: string, exitCode: int}
     * @throws BackupException
     */
    public function pruneArchives(
        string $repository,
        string $passphrase,
        int $keepDaily,
        int $keepWeekly = 4,
        int $keepMonthly = 6,
        int $keepYearly = 0
    ): array {
        $arguments = [
            'prune',
            '--list',
            '--stats',
            '--save-space',
            '--force', // Don't ask for confirmation
            '--keep-daily', (string)$keepDaily,
            '--keep-weekly', (string)$keepWeekly,
            '--keep-monthly', (string)$keepMonthly,
        ];

        // Only add --keep-yearly if > 0
        if ($keepYearly > 0) {
            $arguments[] = '--keep-yearly';
            $arguments[] = (string)$keepYearly;
        }

        $arguments[] = $repository;

        return $this->execute($arguments, $passphrase, 1800);
    }

    /**
     * Delete a specific archive
     *
     * @throws BackupException
     */
    public function deleteArchive(string $archive, string $passphrase): array
    {
        $arguments = [
            'delete',
            '--stats',
            '--force', // Don't ask for confirmation
            $archive
        ];

        $result = $this->execute($arguments, $passphrase, 300);

        if ($result['exitCode'] !== 0) {
            throw new BackupException("Failed to delete archive: {$result['stderr']}");
        }

        $this->logger->info("Archive deleted: {$archive}", 'BORG');
        return $result;
    }

    /**
     * Mount an archive
     *
     * @throws BackupException
     */
    public function mountArchive(string $archive, string $mountPoint, string $passphrase): void
    {
        // Mount with allow_other and force ownership to www-data (UID 33, GID 33)
        // This allows www-data to read all files regardless of original permissions
        $result = $this->execute(
            ['mount', $archive, $mountPoint, '-o', 'allow_other,uid=33,gid=33'],
            $passphrase,
            60
        );

        // Log stderr even on success to debug
        if (!empty($result['stderr'])) {
            $this->logger->info("Borg mount stderr: {$result['stderr']}", 'BORG');
        }
        if (!empty($result['stdout'])) {
            $this->logger->info("Borg mount stdout: {$result['stdout']}", 'BORG');
        }

        if ($result['exitCode'] !== 0) {
            throw new BackupException("Failed to mount archive (exit {$result['exitCode']}): {$result['stderr']}");
        }

        // Verify mount was successful by checking if directory is actually mounted
        sleep(1); // Give FUSE time to mount
        if (!is_dir($mountPoint)) {
            throw new BackupException("Mount succeeded but directory does not exist: {$mountPoint}");
        }

        if (!$this->isMounted($mountPoint)) {
            throw new BackupException("Mount succeeded but directory is not in /proc/mounts. Stderr: {$result['stderr']}");
        }

        $this->logger->info("Archive mounted at: {$mountPoint}", 'BORG');
    }

    /**
     * Check if a directory is a mount point
     */
    private function isMounted(string $path): bool
    {
        // Check if path is in /proc/mounts
        $mounts = file_get_contents('/proc/mounts');
        return strpos($mounts, $path) !== false;
    }

    /**
     * Unmount an archive
     *
     * @throws BackupException
     */
    public function umountArchive(string $mountPoint): void
    {
        $result = $this->execute(
            ['umount', $mountPoint],
            '',
            60
        );

        if ($result['exitCode'] !== 0) {
            throw new BackupException("Failed to unmount archive: {$result['stderr']}");
        }

        $this->logger->info("Archive unmounted: {$mountPoint}", 'BORG');
    }
}
