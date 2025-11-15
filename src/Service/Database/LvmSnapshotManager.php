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

        // PostgreSQL requires pg_start_backup() before snapshot and pg_stop_backup() after
        // We use a single shell command to ensure atomicity:
        // 1. pg_start_backup() - Puts PostgreSQL in backup mode
        // 2. Create LVM snapshot while in backup mode
        // 3. pg_stop_backup() - Exits backup mode

        // Build psql command based on auth method
        // For PostgreSQL, peer auth (user=postgres, no password) uses local Unix socket
        if ($dbInfo->dbUser === 'postgres' && empty($dbInfo->dbPassword)) {
            // Peer auth - use su to postgres user, connect via Unix socket (no -h)
            $psqlBase = "su - postgres -c 'psql'";
        } elseif (!empty($dbInfo->dbPassword)) {
            // Password authentication
            $host = escapeshellarg($dbInfo->dbHost);
            $user = escapeshellarg($dbInfo->dbUser);
            $pass = escapeshellarg($dbInfo->dbPassword);
            $psqlBase = "PGPASSWORD={$pass} psql -U {$user} -h {$host}";
        } else {
            // Trust auth or .pgpass file
            $host = escapeshellarg($dbInfo->dbHost);
            $user = escapeshellarg($dbInfo->dbUser);
            $psqlBase = "psql -U {$user} -h {$host}";
        }

        // Atomic command: start backup, create snapshot, stop backup
        // Handle quote escaping based on auth method
        // Note: pg_start_backup() uses 2 params (label, fast) in PG 9.6-14, 3 params in PG 15+
        // We use 2 params for compatibility with PG 12
        if ($dbInfo->dbUser === 'postgres' && empty($dbInfo->dbPassword)) {
            // For su - postgres, we need different quoting
            $command = sprintf(
                "su - postgres -c \"psql -c \\\"SELECT pg_start_backup('phpborg_lvm_snapshot', true);\\\"\" && " .
                "lvcreate -s /dev/%s/%s -n %s -L%s && " .
                "su - postgres -c \"psql -c \\\"SELECT pg_stop_backup();\\\"\"",
                $dbInfo->vgName,
                $dbInfo->lvmPartition,
                $snapName,
                $dbInfo->lvSize
            );
        } else {
            // For direct psql with PGPASSWORD or trust
            $command = sprintf(
                "%s -c \"SELECT pg_start_backup('phpborg_lvm_snapshot', true);\" && " .
                "lvcreate -s /dev/%s/%s -n %s -L%s && " .
                "%s -c \"SELECT pg_stop_backup();\"",
                $psqlBase,
                $dbInfo->vgName,
                $dbInfo->lvmPartition,
                $snapName,
                $dbInfo->lvSize,
                $psqlBase
            );
        }

        $result = $this->sshExecutor->execute($server, $command, 120);

        if ($result['exitCode'] !== 0) {
            // Try to stop backup mode if it was started
            if ($dbInfo->dbUser === 'postgres' && empty($dbInfo->dbPassword)) {
                $stopBackupCmd = "su - postgres -c \"psql -c \\\"SELECT pg_stop_backup();\\\"\" 2>/dev/null || true";
            } else {
                $stopBackupCmd = "{$psqlBase} -c \"SELECT pg_stop_backup();\" 2>/dev/null || true";
            }
            $this->sshExecutor->execute($server, $stopBackupCmd, 30);

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

        // Unmount if mounted (force unmount with -fl)
        $umountCommand = sprintf(
            "if mount | grep -q '%s'; then umount -fl %s 2>&1 || true; fi",
            $mountPoint,
            escapeshellarg($mountPoint)
        );

        $this->sshExecutor->execute($server, $umountCommand, 30);

        // Remove LVM snapshot if exists
        $removeCommand = sprintf(
            "if lvs %s/%s >/dev/null 2>&1; then lvremove -f %s/%s 2>&1 || true; fi",
            escapeshellarg($dbInfo->vgName),
            escapeshellarg($snapName),
            escapeshellarg($dbInfo->vgName),
            escapeshellarg($snapName)
        );

        $this->sshExecutor->execute($server, $removeCommand, 60);
    }

    /**
     * Create LVM snapshot for MongoDB
     *
     * @throws BackupException
     */
    public function createMongoSnapshot(Server $server, DatabaseInfo $dbInfo): string
    {
        $snapName = $this->config->borgLvmSnapName;
        $mountPoint = "/{$snapName}";

        $this->logger->info("Creating LVM snapshot for MongoDB on {$server->name}", $server->name);

        // Cleanup any existing snapshot
        $this->cleanupSnapshot($server, $dbInfo, $mountPoint);

        // MongoDB requires fsyncLock() before snapshot and fsyncUnlock() after
        // We use a single shell command to ensure atomicity:
        // 1. db.fsyncLock() - Flushes all pending writes and locks the database
        // 2. Create LVM snapshot while locked
        // 3. db.fsyncUnlock() - Unlocks the database

        // Build mongo/mongosh command
        $host = escapeshellarg($dbInfo->dbHost);
        $mongoCmd = 'mongosh'; // Try new shell first

        // Check which mongo client is available
        $checkCmd = "command -v mongosh >/dev/null 2>&1 && echo 'mongosh' || echo 'mongo'";
        $checkResult = $this->sshExecutor->execute($server, $checkCmd, 5);
        if (trim($checkResult['stdout']) === 'mongo') {
            $mongoCmd = 'mongo';
        }

        // Atomic command: lock, create snapshot, unlock
        $command = sprintf(
            "%s %s --quiet --eval 'db.fsyncLock()' && " .
            "lvcreate -s /dev/%s/%s -n %s -L%s && " .
            "%s %s --quiet --eval 'db.fsyncUnlock()'",
            $mongoCmd,
            $host,
            $dbInfo->vgName,
            $dbInfo->lvmPartition,
            $snapName,
            $dbInfo->lvSize,
            $mongoCmd,
            $host
        );

        $result = $this->sshExecutor->execute($server, $command, 120);

        if ($result['exitCode'] !== 0) {
            // Try to unlock if it was locked
            $unlockCmd = sprintf(
                "%s %s --quiet --eval 'db.fsyncUnlock()' 2>/dev/null || true",
                $mongoCmd,
                $host
            );
            $this->sshExecutor->execute($server, $unlockCmd, 30);

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

        $this->logger->info("LVM snapshot created and mounted at {$mountPoint}", $server->name);

        return $mountPoint;
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
