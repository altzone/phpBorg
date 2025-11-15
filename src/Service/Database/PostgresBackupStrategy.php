<?php

declare(strict_types=1);

namespace PhpBorg\Service\Database;

use PhpBorg\Entity\DatabaseInfo;
use PhpBorg\Entity\Server;
use PhpBorg\Exception\BackupException;
use PhpBorg\Logger\LoggerInterface;
use PhpBorg\Service\Server\SshExecutor;

/**
 * PostgreSQL backup strategy
 * Supports both LVM snapshots and pg_dump
 */
final class PostgresBackupStrategy implements DatabaseBackupInterface
{
    private const DUMP_DIR = '/tmp/phpborg_postgres_dump';

    public function __construct(
        private readonly LvmSnapshotManager $lvmManager,
        private readonly SshExecutor $sshExecutor,
        private readonly LoggerInterface $logger,
        private readonly bool $useLvm = true,
    ) {
    }

    public function prepareBackup(Server $server, DatabaseInfo $dbInfo): array
    {
        if ($this->useLvm) {
            return $this->prepareLvmBackup($server, $dbInfo);
        }

        return $this->prepareDumpBackup($server, $dbInfo);
    }

    public function cleanupBackup(Server $server, DatabaseInfo $dbInfo): void
    {
        if ($this->useLvm) {
            $this->logger->info("Cleaning up PostgreSQL LVM backup", $server->name);
            $this->lvmManager->removeSnapshot($server, $dbInfo);
        } else {
            $this->logger->info("Cleaning up PostgreSQL dump backup", $server->name);
            $this->sshExecutor->execute($server, "rm -rf " . self::DUMP_DIR, 60);
        }
    }

    public function getSupportedType(): string
    {
        return 'postgres';
    }

    /**
     * Prepare backup using LVM snapshot
     */
    private function prepareLvmBackup(Server $server, DatabaseInfo $dbInfo): array
    {
        $this->logger->info("Preparing PostgreSQL backup using LVM snapshot", $server->name);

        try {
            $mountPoint = $this->lvmManager->createPostgresSnapshot($server, $dbInfo);

            // PostgreSQL backup includes both data and configuration
            // Data: from LVM snapshot (consistent point-in-time)
            // Config: from live filesystem (/etc/postgresql contains all cluster configs)
            return [
                'paths' => [
                    $mountPoint . $dbInfo->mysqlPath, // Data dir from snapshot
                    '/etc/postgresql'                  // PostgreSQL configuration directory
                ],
                'cleanup' => true,
            ];
        } catch (BackupException $e) {
            $this->logger->error("Failed to prepare PostgreSQL LVM backup: {$e->getMessage()}", $server->name);
            throw $e;
        }
    }

    /**
     * Prepare backup using pg_dump
     */
    private function prepareDumpBackup(Server $server, DatabaseInfo $dbInfo): array
    {
        $this->logger->info("Preparing PostgreSQL backup using pg_dump", $server->name);

        // Create dump directory
        $this->sshExecutor->createDirectory($server, self::DUMP_DIR, 0700);

        // Run pg_dumpall to backup all databases
        $dumpFile = self::DUMP_DIR . '/postgres_dump.sql';
        $command = sprintf(
            "PGPASSWORD='%s' pg_dumpall -h %s -U %s > %s",
            $dbInfo->dbPassword,
            $dbInfo->dbHost,
            $dbInfo->dbUser,
            $dumpFile
        );

        $result = $this->sshExecutor->execute($server, $command, 1800);

        if ($result['exitCode'] !== 0) {
            $this->sshExecutor->execute($server, "rm -rf " . self::DUMP_DIR, 30);
            throw new BackupException("Failed to create PostgreSQL dump: {$result['stderr']}");
        }

        $this->logger->info("PostgreSQL dump created successfully", $server->name);

        return [
            'path' => self::DUMP_DIR,
            'cleanup' => true,
        ];
    }
}
