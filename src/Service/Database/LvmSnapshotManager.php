<?php

declare(strict_types=1);

namespace PhpBorg\Service\Database;

use PhpBorg\Config\Configuration;
use PhpBorg\Entity\DatabaseInfo;
use PhpBorg\Entity\Server;
use PhpBorg\Exception\BackupException;
use PhpBorg\Logger\LoggerInterface;
use PhpBorg\Service\Server\SshExecutor;

/**
 * Manage LVM snapshots for database backups
 */
final class LvmSnapshotManager
{
    public function __construct(
        private readonly Configuration $config,
        private readonly SshExecutor $sshExecutor,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Create LVM snapshot for MySQL
     *
     * @throws BackupException
     */
    public function createMysqlSnapshot(Server $server, DatabaseInfo $dbInfo): string
    {
        $snapName = $this->config->borgLvmSnapName;
        $mountPoint = "/{$snapName}";

        $this->logger->info("Creating LVM snapshot for MySQL on {$server->name}", $server->name);

        // Cleanup any existing snapshot
        $this->cleanupSnapshot($server, $dbInfo, $mountPoint);

        // Create snapshot with MySQL lock
        $command = sprintf(
            "mysql -u%s -p%s -h %s -e 'flush tables with read lock; " .
            "system lvcreate -s /dev/%s/%s -n %s -L%s 1>&2; " .
            "unlock tables;'",
            $dbInfo->dbUser,
            $dbInfo->dbPassword,
            $dbInfo->dbHost,
            $dbInfo->vgName,
            $dbInfo->lvmPartition,
            $snapName,
            $dbInfo->lvSize
        );

        $result = $this->sshExecutor->execute($server, $command, 120);

        if ($result['exitCode'] !== 0) {
            $this->logger->error(
                "Failed to create LVM snapshot: {$result['stderr']}",
                $server->name
            );
            throw new BackupException("Failed to create LVM snapshot: {$result['stderr']}");
        }

        // Mount the snapshot
        $mountCommand = sprintf(
            "[[ ! -d %s ]] && mkdir -p %s; mount /dev/%s/%s %s",
            $mountPoint,
            $mountPoint,
            $dbInfo->vgName,
            $snapName,
            $mountPoint
        );

        $result = $this->sshExecutor->execute($server, $mountCommand, 60);

        if ($result['exitCode'] !== 0) {
            // Try to cleanup the snapshot
            $this->removeSnapshot($server, $dbInfo);
            throw new BackupException("Failed to mount LVM snapshot: {$result['stderr']}");
        }

        $this->logger->info("LVM snapshot created and mounted at {$mountPoint}", $server->name);

        return $mountPoint;
    }

    /**
     * Create LVM snapshot for PostgreSQL
     *
     * @throws BackupException
     */
    public function createPostgresSnapshot(Server $server, DatabaseInfo $dbInfo): string
    {
        $snapName = $this->config->borgLvmSnapName;
        $mountPoint = "/{$snapName}";

        $this->logger->info("Creating LVM snapshot for PostgreSQL on {$server->name}", $server->name);

        // Cleanup any existing snapshot
        $this->cleanupSnapshot($server, $dbInfo, $mountPoint);

        // For PostgreSQL, we need to use pg_start_backup and pg_stop_backup
        // Or use pg_basebackup which handles this automatically

        // Create snapshot
        $command = sprintf(
            "lvcreate -s /dev/%s/%s -n %s -L%s",
            $dbInfo->vgName,
            $dbInfo->lvmPartition,
            $snapName,
            $dbInfo->lvSize
        );

        $result = $this->sshExecutor->execute($server, $command, 120);

        if ($result['exitCode'] !== 0) {
            throw new BackupException("Failed to create LVM snapshot: {$result['stderr']}");
        }

        // Mount the snapshot
        $mountCommand = sprintf(
            "[[ ! -d %s ]] && mkdir -p %s; mount /dev/%s/%s %s",
            $mountPoint,
            $mountPoint,
            $dbInfo->vgName,
            $snapName,
            $mountPoint
        );

        $result = $this->sshExecutor->execute($server, $mountCommand, 60);

        if ($result['exitCode'] !== 0) {
            $this->removeSnapshot($server, $dbInfo);
            throw new BackupException("Failed to mount LVM snapshot: {$result['stderr']}");
        }

        $this->logger->info("LVM snapshot created and mounted", $server->name);

        return $mountPoint;
    }

    /**
     * Remove LVM snapshot
     *
     * @throws BackupException
     */
    public function removeSnapshot(Server $server, DatabaseInfo $dbInfo): void
    {
        $snapName = $this->config->borgLvmSnapName;
        $mountPoint = "/{$snapName}";

        $this->logger->info("Removing LVM snapshot", $server->name);

        $this->cleanupSnapshot($server, $dbInfo, $mountPoint);

        $this->logger->info("LVM snapshot removed", $server->name);
    }

    /**
     * Cleanup snapshot (unmount and remove)
     */
    private function cleanupSnapshot(Server $server, DatabaseInfo $dbInfo, string $mountPoint): void
    {
        $snapName = $this->config->borgLvmSnapName;

        // Unmount if mounted
        $umountCommand = sprintf(
            "a=(\`mount\`); [[ \${a[*]} =~ %s ]] && umount -fl %s 1>&2",
            $snapName,
            $mountPoint
        );

        $this->sshExecutor->execute($server, $umountCommand, 30);

        // Remove LVM snapshot if exists
        $removeCommand = sprintf(
            "b=(\`lvs\`); [[ \${b[*]} =~ %s ]] && lvremove -f /dev/%s/%s 1>&2",
            $snapName,
            $dbInfo->vgName,
            $snapName
        );

        $this->sshExecutor->execute($server, $removeCommand, 60);
    }

    /**
     * Check if LVM snapshot exists
     */
    public function snapshotExists(Server $server, DatabaseInfo $dbInfo): bool
    {
        $snapName = $this->config->borgLvmSnapName;

        $command = sprintf(
            "lvs /dev/%s/%s 2>/dev/null && echo 'exists'",
            $dbInfo->vgName,
            $snapName
        );

        $result = $this->sshExecutor->execute($server, $command, 10);

        return $result['exitCode'] === 0 && str_contains($result['stdout'], 'exists');
    }
}
