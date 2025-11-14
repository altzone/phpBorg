<?php

declare(strict_types=1);

namespace PhpBorg\Service\Database;

use PhpBorg\Entity\DatabaseInfo;
use PhpBorg\Entity\Server;
use PhpBorg\Exception\BackupException;
use PhpBorg\Logger\LoggerInterface;

/**
 * MongoDB backup strategy using LVM snapshots
 * Ensures atomic, consistent backups without blocking production
 */
final class MongoDbBackupStrategy implements DatabaseBackupInterface
{
    public function __construct(
        private readonly LvmSnapshotManager $lvmManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function prepareBackup(Server $server, DatabaseInfo $dbInfo): array
    {
        $this->logger->info("Preparing MongoDB backup using LVM snapshot", $server->name);

        try {
            $mountPoint = $this->lvmManager->createMongoSnapshot($server, $dbInfo);

            return [
                'path' => $mountPoint . $dbInfo->mysqlPath, // reuse field for MongoDB datadir
                'cleanup' => true,
            ];
        } catch (BackupException $e) {
            $this->logger->error("Failed to prepare MongoDB backup: {$e->getMessage()}", $server->name);
            throw $e;
        }
    }

    public function cleanupBackup(Server $server, DatabaseInfo $dbInfo): void
    {
        $this->logger->info("Cleaning up MongoDB backup", $server->name);

        try {
            $this->lvmManager->removeSnapshot($server, $dbInfo);
        } catch (BackupException $e) {
            $this->logger->warning("Failed to cleanup MongoDB backup: {$e->getMessage()}", $server->name);
        }
    }

    public function getSupportedType(): string
    {
        return 'mongodb';
    }
}
