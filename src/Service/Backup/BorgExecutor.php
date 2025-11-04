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
     * @return array{stdout: string, stderr: string, exitCode: int}
     * @throws BackupException
     */
    public function execute(array $arguments, string $passphrase, int $timeout = 3600): array
    {
        $command = array_merge([$this->config->borgBinaryPath], $arguments);

        $env = [
            'BORG_PASSPHRASE' => $passphrase,
            'BORG_RELOCATED_REPO_ACCESS_IS_OK' => 'yes',
            'BORG_UNKNOWN_UNENCRYPTED_REPO_ACCESS_IS_OK' => 'no',
        ];

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
     * Get archive info as JSON
     *
     * @return array<string, mixed>
     * @throws BackupException
     */
    public function getArchiveInfo(string $archive, string $passphrase): array
    {
        $result = $this->execute(
            ['info', '--json', $archive],
            $passphrase,
            60
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
     * Prune old archives
     *
     * @return array{stdout: string, stderr: string, exitCode: int}
     * @throws BackupException
     */
    public function pruneArchives(
        string $repository,
        string $passphrase,
        int $keepDaily,
        int $keepWeekly = 4,
        int $keepMonthly = 6
    ): array {
        return $this->execute(
            [
                'prune',
                '--list',
                '--stats',
                '--save-space',
                '--keep-daily', (string)$keepDaily,
                '--keep-weekly', (string)$keepWeekly,
                '--keep-monthly', (string)$keepMonthly,
                $repository,
            ],
            $passphrase,
            1800
        );
    }

    /**
     * Mount an archive
     *
     * @throws BackupException
     */
    public function mountArchive(string $archive, string $mountPoint, string $passphrase): void
    {
        $result = $this->execute(
            ['mount', $archive, $mountPoint],
            $passphrase,
            60
        );

        if ($result['exitCode'] !== 0) {
            throw new BackupException("Failed to mount archive: {$result['stderr']}");
        }

        $this->logger->info("Archive mounted at: {$mountPoint}", 'BORG');
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
