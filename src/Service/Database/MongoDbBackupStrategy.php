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

            // MongoDB backup includes both data and configuration
            // Data: from LVM snapshot (consistent point-in-time)
            // Config: from live filesystem (/etc/mongod.conf or /etc/mongodb.conf)
            return [
                'paths' => [
                    $mountPoint . $dbInfo->mysqlPath, // Data dir from snapshot
                    '/etc/mongod.conf',                // MongoDB config (standard)
                    '/etc/mongodb.conf'                // MongoDB config (alternative)
                ],
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
