<?php

declare(strict_types=1);

namespace PhpBorg\Service\Database;

use PhpBorg\Entity\DatabaseInfo;
use PhpBorg\Entity\Server;
use PhpBorg\Exception\BackupException;
use PhpBorg\Logger\LoggerInterface;
use PhpBorg\Service\Server\SshExecutor;

/**
 * MongoDB backup strategy using mongodump
 */
final class MongoDbBackupStrategy implements DatabaseBackupInterface
{
    private const BACKUP_DIR = '/tmp/phpborg_mongodb_dump';

    public function __construct(
        private readonly SshExecutor $sshExecutor,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function prepareBackup(Server $server, DatabaseInfo $dbInfo): array
    {
        $this->logger->info("Preparing MongoDB backup using mongodump", $server->name);

        // Create backup directory
        $this->sshExecutor->createDirectory($server, self::BACKUP_DIR, 0700);

        // Build mongodump command
        $command = sprintf(
            "mongodump --host %s --username %s --password '%s' --out %s --gzip",
            $dbInfo->dbHost,
            $dbInfo->dbUser,
            $dbInfo->dbPassword,
            self::BACKUP_DIR
        );

        // Add authentication database if needed
        if (!empty($dbInfo->mysqlPath)) { // reuse field for auth database
            $command .= " --authenticationDatabase " . $dbInfo->mysqlPath;
        }

        $result = $this->sshExecutor->execute($server, $command, 3600);

        if ($result['exitCode'] !== 0) {
            $this->sshExecutor->execute($server, "rm -rf " . self::BACKUP_DIR, 30);
            throw new BackupException("Failed to create MongoDB dump: {$result['stderr']}");
        }

        $this->logger->info("MongoDB dump created successfully", $server->name);

        return [
            'path' => self::BACKUP_DIR,
            'cleanup' => true,
        ];
    }

    public function cleanupBackup(Server $server, DatabaseInfo $dbInfo): void
    {
        $this->logger->info("Cleaning up MongoDB backup", $server->name);

        $result = $this->sshExecutor->execute($server, "rm -rf " . self::BACKUP_DIR, 60);

        if ($result['exitCode'] !== 0) {
            $this->logger->warning("Failed to cleanup MongoDB backup directory", $server->name);
        }
    }

    public function getSupportedType(): string
    {
        return 'mongodb';
    }
}
