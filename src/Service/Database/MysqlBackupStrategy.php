<?php

declare(strict_types=1);

namespace PhpBorg\Service\Database;

use PhpBorg\Entity\DatabaseInfo;
use PhpBorg\Entity\Server;
use PhpBorg\Exception\BackupException;
use PhpBorg\Logger\LoggerInterface;

/**
 * MySQL backup strategy using LVM snapshots
 */
final class MysqlBackupStrategy implements DatabaseBackupInterface
{
    public function __construct(
        private readonly LvmSnapshotManager $lvmManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function prepareBackup(Server $server, DatabaseInfo $dbInfo): array
    {
        $this->logger->info("Preparing MySQL backup using LVM snapshot", $server->name);

        try {
            $mountPoint = $this->lvmManager->createMysqlSnapshot($server, $dbInfo);

            return [
                'path' => $mountPoint . $dbInfo->mysqlPath,
                'cleanup' => true,
            ];
        } catch (BackupException $e) {
            $this->logger->error("Failed to prepare MySQL backup: {$e->getMessage()}", $server->name);
            throw $e;
        }
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
