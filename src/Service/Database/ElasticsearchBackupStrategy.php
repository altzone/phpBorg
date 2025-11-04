<?php

declare(strict_types=1);

namespace PhpBorg\Service\Database;

use PhpBorg\Entity\DatabaseInfo;
use PhpBorg\Entity\Server;
use PhpBorg\Exception\BackupException;
use PhpBorg\Logger\LoggerInterface;
use PhpBorg\Service\Server\SshExecutor;

/**
 * Elasticsearch backup strategy using snapshot API
 */
final class ElasticsearchBackupStrategy implements DatabaseBackupInterface
{
    private const BACKUP_DIR = '/tmp/phpborg_elasticsearch_backup';

    public function __construct(
        private readonly SshExecutor $sshExecutor,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function prepareBackup(Server $server, DatabaseInfo $dbInfo): array
    {
        $this->logger->info("Preparing Elasticsearch backup", $server->name);

        // Create backup directory
        $this->sshExecutor->createDirectory($server, self::BACKUP_DIR, 0755);

        // Register snapshot repository
        $repoName = 'phpborg_backup';
        $registerCommand = sprintf(
            "curl -X PUT '%s:%s/_snapshot/%s' -H 'Content-Type: application/json' -d '{
                \"type\": \"fs\",
                \"settings\": {
                    \"location\": \"%s\",
                    \"compress\": true
                }
            }'",
            $dbInfo->dbHost,
            $dbInfo->dbUser, // reuse as port
            $repoName,
            self::BACKUP_DIR
        );

        $result = $this->sshExecutor->execute($server, $registerCommand, 60);

        if ($result['exitCode'] !== 0) {
            throw new BackupException("Failed to register Elasticsearch snapshot repository: {$result['stderr']}");
        }

        // Create snapshot
        $snapshotName = 'snapshot_' . date('Y-m-d_H-i-s');
        $snapshotCommand = sprintf(
            "curl -X PUT '%s:%s/_snapshot/%s/%s?wait_for_completion=true'",
            $dbInfo->dbHost,
            $dbInfo->dbUser,
            $repoName,
            $snapshotName
        );

        $result = $this->sshExecutor->execute($server, $snapshotCommand, 3600);

        if ($result['exitCode'] !== 0) {
            throw new BackupException("Failed to create Elasticsearch snapshot: {$result['stderr']}");
        }

        $this->logger->info("Elasticsearch snapshot created: {$snapshotName}", $server->name);

        return [
            'path' => self::BACKUP_DIR,
            'cleanup' => true,
        ];
    }

    public function cleanupBackup(Server $server, DatabaseInfo $dbInfo): void
    {
        $this->logger->info("Cleaning up Elasticsearch backup", $server->name);

        // Optionally delete the snapshot from Elasticsearch
        // For now, just keep the filesystem backup

        // Note: We don't delete BACKUP_DIR as it contains the actual snapshot data
        // It will be backed up by Borg
    }

    public function getSupportedType(): string
    {
        return 'elasticsearch';
    }
}
