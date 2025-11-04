<?php

declare(strict_types=1);

namespace PhpBorg\Service\Backup;

use PhpBorg\Config\Configuration;
use PhpBorg\Entity\Archive;
use PhpBorg\Entity\BorgRepository;
use PhpBorg\Entity\Server;
use PhpBorg\Exception\BackupException;
use PhpBorg\Logger\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Mount and restore service for Borg archives
 */
final class MountService
{
    public function __construct(
        private readonly Configuration $config,
        private readonly BorgExecutor $borgExecutor,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Get mount point for a server
     */
    public function getMountPoint(Server $server): string
    {
        return $this->config->borgBackupPath . '/' . $server->name . '/restore';
    }

    /**
     * Check if mount point exists and is empty
     */
    public function isMountPointAvailable(string $mountPoint): bool
    {
        if (!is_dir($mountPoint)) {
            return true;
        }

        // Check if already mounted
        $mounts = @file_get_contents('/proc/mounts');
        if ($mounts !== false && str_contains($mounts, $mountPoint)) {
            return false;
        }

        return true;
    }

    /**
     * Mount a Borg archive and start interactive shell
     *
     * @throws BackupException
     */
    public function mountArchiveInteractive(
        BorgRepository $repository,
        Archive $archive,
        Server $server
    ): int {
        $mountPoint = $this->getMountPoint($server);

        // Create mount point if it doesn't exist
        if (!is_dir($mountPoint)) {
            if (!mkdir($mountPoint, 0755, true) && !is_dir($mountPoint)) {
                throw new BackupException("Failed to create mount point: {$mountPoint}");
            }
        }

        // Check if already mounted
        if (!$this->isMountPointAvailable($mountPoint)) {
            throw new BackupException("Mount point is already in use: {$mountPoint}. Unmount first.");
        }

        $this->logger->info("Mounting archive: {$archive->name}", $server->name);

        try {
            // Mount the archive
            $archivePath = $repository->repoPath . '::' . $archive->name;
            $this->borgExecutor->mountArchive($archivePath, $mountPoint, $repository->passphrase);

            $this->logger->info("Archive mounted at: {$mountPoint}", $server->name);

            echo "\n";
            echo "═══════════════════════════════════════════════════════════════\n";
            echo "  🗂️  Backup Mounted Successfully!\n";
            echo "═══════════════════════════════════════════════════════════════\n";
            echo "  Server:   {$server->name}\n";
            echo "  Archive:  {$archive->name}\n";
            echo "  Date:     {$archive->end->format('Y-m-d H:i:s')}\n";
            echo "  Location: {$mountPoint}\n";
            echo "═══════════════════════════════════════════════════════════════\n";
            echo "\n";
            echo "Starting interactive shell...\n";
            echo "Type 'exit' to unmount and quit.\n";
            echo "\n";

            // Start interactive bash shell with custom prompt
            $bashCommand = sprintf(
                'cd %s && PS1="[\[\033[32m\]\w]\[\033[0m\]\n\[\033[1;36m\]%s BACKUP\[\033[1;33m\]-> \[\033[0m\]" bash --noprofile --norc -i',
                escapeshellarg($mountPoint),
                escapeshellarg($server->name)
            );

            $process = Process::fromShellCommandline($bashCommand);
            $process->setTty(true);
            $process->setTimeout(null);
            $exitCode = $process->run();

            echo "\n";
            echo "Unmounting backup... ";

            // Unmount
            $this->borgExecutor->umountArchive($mountPoint);

            echo "✓ Done\n";
            $this->logger->info("Archive unmounted successfully", $server->name);

            return $exitCode;

        } catch (\Exception $e) {
            // Try to unmount on error
            try {
                if (!$this->isMountPointAvailable($mountPoint)) {
                    $this->borgExecutor->umountArchive($mountPoint);
                }
            } catch (\Exception $unmountError) {
                $this->logger->warning(
                    "Failed to unmount after error: {$unmountError->getMessage()}",
                    $server->name
                );
            }

            throw new BackupException("Mount failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Unmount a Borg archive
     *
     * @throws BackupException
     */
    public function umountArchive(string $mountPoint): void
    {
        $this->logger->info("Unmounting archive from: {$mountPoint}", 'RESTORE');

        if ($this->isMountPointAvailable($mountPoint)) {
            throw new BackupException("Nothing mounted at: {$mountPoint}");
        }

        $this->borgExecutor->umountArchive($mountPoint);

        $this->logger->info("Archive unmounted successfully", 'RESTORE');
    }

    /**
     * Extract specific files from archive
     *
     * @param array<int, string> $paths
     * @throws BackupException
     */
    public function extractFiles(
        BorgRepository $repository,
        Archive $archive,
        array $paths,
        string $destination
    ): void {
        $this->logger->info("Extracting files from archive: {$archive->name}", 'RESTORE');

        if (!is_dir($destination)) {
            if (!mkdir($destination, 0755, true) && !is_dir($destination)) {
                throw new BackupException("Failed to create destination directory: {$destination}");
            }
        }

        $arguments = [
            'extract',
            '--strip-components', '1',
            $repository->repoPath . '::' . $archive->name,
            ...$paths,
        ];

        $result = $this->borgExecutor->execute(
            $arguments,
            $repository->passphrase,
            1800
        );

        if ($result['exitCode'] !== 0) {
            throw new BackupException("Failed to extract files: {$result['stderr']}");
        }

        $this->logger->info("Files extracted successfully to: {$destination}", 'RESTORE');
    }

    /**
     * List files in an archive
     *
     * @return array<int, array<string, mixed>>
     * @throws BackupException
     */
    public function listArchiveContents(
        BorgRepository $repository,
        Archive $archive,
        ?string $path = null
    ): array {
        $this->logger->debug("Listing contents of archive: {$archive->name}", 'RESTORE');

        $arguments = [
            'list',
            '--json-lines',
            $repository->repoPath . '::' . $archive->name,
        ];

        if ($path !== null) {
            $arguments[] = $path;
        }

        $result = $this->borgExecutor->execute(
            $arguments,
            $repository->passphrase,
            300
        );

        if ($result['exitCode'] !== 0) {
            throw new BackupException("Failed to list archive contents: {$result['stderr']}");
        }

        // Parse JSON lines
        $files = [];
        $lines = explode("\n", trim($result['stdout']));
        foreach ($lines as $line) {
            if (empty($line)) {
                continue;
            }
            $data = json_decode($line, true);
            if (is_array($data) && isset($data['path'])) {
                $files[] = $data;
            }
        }

        return $files;
    }

    /**
     * Compare two archives
     *
     * @return array<string, mixed>
     * @throws BackupException
     */
    public function compareArchives(
        BorgRepository $repository,
        Archive $archive1,
        Archive $archive2
    ): array {
        $this->logger->info(
            "Comparing archives: {$archive1->name} vs {$archive2->name}",
            'RESTORE'
        );

        $arguments = [
            'diff',
            $repository->repoPath . '::' . $archive1->name,
            $repository->repoPath . '::' . $archive2->name,
        ];

        $result = $this->borgExecutor->execute(
            $arguments,
            $repository->passphrase,
            600
        );

        return [
            'archive1' => $archive1->name,
            'archive2' => $archive2->name,
            'differences' => $result['stdout'],
            'summary' => $result['stderr'],
            'exitCode' => $result['exitCode'],
        ];
    }
}
