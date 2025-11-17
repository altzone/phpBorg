<?php

declare(strict_types=1);

namespace PhpBorg\Service\Database;

use PhpBorg\Entity\DatabaseInfo;
use PhpBorg\Entity\Server;
use PhpBorg\Exception\BackupException;
use PhpBorg\Logger\LoggerInterface;
use PhpBorg\Service\Server\SshExecutor;

/**
 * MySQL backup strategy using LVM snapshots
 */
final class MysqlBackupStrategy implements DatabaseBackupInterface
{
    public function __construct(
        private readonly LvmSnapshotManager $lvmManager,
        private readonly LoggerInterface $logger,
        private readonly SshExecutor $sshExecutor,
    ) {
    }

    public function prepareBackup(Server $server, DatabaseInfo $dbInfo): array
    {
        $this->logger->info("Preparing MySQL backup using LVM snapshot", $server->name);

        try {
            $mountPoint = $this->lvmManager->createMysqlSnapshot($server, $dbInfo);

            // MySQL backup includes both data and configuration
            // Data: from LVM snapshot (consistent point-in-time)
            // Config: dynamically detected from filesystem

            $paths = [
                $mountPoint . $dbInfo->mysqlPath, // Data dir from snapshot
            ];

            // Detect existing MySQL config files
            $configPaths = $this->detectMysqlConfigFiles($server);
            foreach ($configPaths as $configPath) {
                $paths[] = $configPath;
            }

            return [
                'paths' => $paths,
                'cleanup' => true,
            ];
        } catch (BackupException $e) {
            $this->logger->error("Failed to prepare MySQL backup: {$e->getMessage()}", $server->name);
            throw $e;
        }
    }

    /**
     * Detect existing MySQL configuration files on the server
     * Returns only files/directories that actually exist
     */
    private function detectMysqlConfigFiles(Server $server): array
    {
        $candidatePaths = [
            '/etc/mysql',           // MySQL config directory (Debian/Ubuntu)
            '/etc/my.cnf',          // Global config (RHEL/CentOS)
            '/etc/my.cnf.d',        // Config directory (RHEL/CentOS 7+)
            '/etc/mysql/my.cnf',    // Main config file (Debian/Ubuntu)
        ];

        $existingPaths = [];

        foreach ($candidatePaths as $path) {
            // Test if file or directory exists
            $result = $this->sshExecutor->execute($server, "test -e {$path} && echo 'exists' || echo 'missing'", 5);

            if ($result['exitCode'] === 0 && trim($result['stdout']) === 'exists') {
                $existingPaths[] = $path;
                $this->logger->info("MySQL config detected: {$path}", $server->name);
            }
        }

        if (empty($existingPaths)) {
            $this->logger->warning("No MySQL config files detected on {$server->name}, backing up data only", $server->name);
        }

        return $existingPaths;
    }

    public function cleanupBackup(Server $server, DatabaseInfo $dbInfo): void
    {
        $this->logger->info("Cleaning up MySQL backup", $server->name);

        try {
            $this->lvmManager->removeSnapshot($server, $dbInfo);
        } catch (BackupException $e) {
            $this->logger->warning("Failed to cleanup MySQL backup: {$e->getMessage()}", $server->name);
        }
    }

    public function getSupportedType(): string
    {
        return 'mysql';
    }
}
