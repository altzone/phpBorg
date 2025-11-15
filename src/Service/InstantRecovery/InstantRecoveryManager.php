<?php

declare(strict_types=1);

namespace PhpBorg\Service\InstantRecovery;

use PhpBorg\Config\Configuration;
use PhpBorg\Entity\Archive;
use PhpBorg\Entity\InstantRecoverySession;
use PhpBorg\Entity\Server;
use PhpBorg\Exception\BackupException;
use PhpBorg\Logger\LoggerInterface;
use PhpBorg\Repository\BorgRepositoryRepository;
use PhpBorg\Repository\InstantRecoverySessionRepository;
use PhpBorg\Service\Backup\BorgExecutor;
use PhpBorg\Service\Server\SshExecutor;

/**
 * Instant Recovery Manager
 * Mounts backups and starts ephemeral database instances for testing/querying
 */
final class InstantRecoveryManager
{
    private const BASE_RECOVERY_DIR = '/tmp/phpborg_instant_recovery';
    private const BASE_PORT_POSTGRESQL = 15432;
    private const BASE_PORT_MYSQL = 13306;
    private const BASE_PORT_MONGODB = 37017;

    public function __construct(
        private readonly Configuration $config,
        private readonly BorgExecutor $borgExecutor,
        private readonly SshExecutor $sshExecutor,
        private readonly InstantRecoverySessionRepository $sessionRepo,
        private readonly BorgRepositoryRepository $repositoryRepo,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Start instant recovery session for an archive
     *
     * Steps:
     * 1. Mount Borg archive (read-only FUSE)
     * 2. Setup OverlayFS with RW layer
     * 3. Start database instance on temporary port
     *
     * @param string $deploymentLocation 'remote' (on source server) or 'local' (on phpBorg server)
     * @throws BackupException
     */
    public function startRecovery(
        Archive $archive,
        Server $server,
        string $dbType,
        string $deploymentLocation = 'remote'
    ): InstantRecoverySession {
        $this->logger->info("Starting instant recovery for archive {$archive->name} (deployment: {$deploymentLocation})", $server->name);

        // Validate deployment location
        if (!in_array($deploymentLocation, ['remote', 'local'])) {
            throw new BackupException("Invalid deployment location: {$deploymentLocation}. Must be 'remote' or 'local'.");
        }

        // Check if already running
        $existing = $this->sessionRepo->findByArchiveId($archive->id);
        if ($existing && $existing->isActive()) {
            $this->logger->info("Recovery session already active for archive {$archive->id}", $server->name);
            return $existing;
        }

        // Find available port
        $port = $this->findAvailablePort($dbType);

        // Generate unique session paths
        $sessionId = uniqid('recovery_', true);
        $borgMount = self::BASE_RECOVERY_DIR . "/borg_mount_{$sessionId}";
        $overlayUpper = self::BASE_RECOVERY_DIR . "/overlay_upper_{$sessionId}";
        $overlayWork = self::BASE_RECOVERY_DIR . "/overlay_work_{$sessionId}";
        $overlayMerged = self::BASE_RECOVERY_DIR . "/overlay_merged_{$sessionId}";

        // Create session in database
        $recoveryId = $this->sessionRepo->create(
            archiveId: $archive->id,
            serverId: $server->id,
            dbType: $dbType,
            deploymentLocation: $deploymentLocation,
            borgMountPoint: $borgMount,
            overlayUpperDir: $overlayUpper,
            overlayWorkDir: $overlayWork,
            overlayMergedDir: $overlayMerged,
            dbPort: $port
        );

        try {
            // Step 1: Mount Borg archive
            $this->mountBorgArchive($server, $archive, $borgMount, $deploymentLocation);

            // Step 2: Find datadir in mounted backup
            $dataDir = $this->findDataDirectoryInMount($server, $dbType, $borgMount, $deploymentLocation);
            if (!$dataDir) {
                throw new BackupException("Could not find {$dbType} data directory in backup");
            }

            // Step 3: Start database instance in READ-ONLY mode
            // Note: Instant Recovery is read-only - perfect for querying/testing without modifying backup
            $dbInfo = $this->startDatabaseInstance($server, $dbType, $dataDir, $port, $deploymentLocation);

            // Mark as active
            $this->sessionRepo->markActive(
                $recoveryId,
                $dbInfo['pid'],
                $dbInfo['socket'] ?? null,
                $dbInfo['connection_string']
            );

            $this->logger->info("Instant recovery started successfully", $server->name);

            return $this->sessionRepo->findById($recoveryId);

        } catch (\Exception $e) {
            // Cleanup on failure
            $this->logger->error("Failed to start instant recovery: {$e->getMessage()}", $server->name);
            $this->sessionRepo->markFailed($recoveryId, $e->getMessage());

            // Attempt cleanup
            try {
                $session = $this->sessionRepo->findById($recoveryId);
                if ($session) {
                    $this->stopRecovery($session, $server);
                }
            } catch (\Exception $cleanupError) {
                $this->logger->error("Cleanup after failure also failed: {$cleanupError->getMessage()}", $server->name);
            }

            throw new BackupException("Failed to start instant recovery: {$e->getMessage()}");
        }
    }

    /**
     * Stop instant recovery session
     */
    public function stopRecovery(InstantRecoverySession $session, Server $server): void
    {
        $this->logger->info("Stopping instant recovery session {$session->id}", $server->name);

        try {
            // Stop database instance
            if ($session->dbPid) {
                $this->stopDatabaseInstance($server, $session->dbType, $session->dbPid, $session->overlayMergedDir, $session->deploymentLocation);
            }

            // Unmount Borg
            $this->unmountBorgArchive($server, $session->borgMountPoint, $session->deploymentLocation);

            // Cleanup directories
            $this->cleanupDirectories($server, [
                $session->borgMountPoint,
                "/tmp/phpborg_pg_socket_" . $session->dbPort,
                "/tmp/phpborg_pg_temp_" . $session->dbPort
            ], $session->deploymentLocation);

            // Mark as stopped
            $this->sessionRepo->markStopped($session->id);

            $this->logger->info("Instant recovery session stopped successfully", $server->name);

        } catch (\Exception $e) {
            $this->logger->error("Error stopping instant recovery: {$e->getMessage()}", $server->name);
            throw new BackupException("Failed to stop instant recovery: {$e->getMessage()}");
        }
    }

    /**
     * Execute command either locally or via SSH depending on deployment location
     *
     * @param bool $useSudo Whether to use sudo (default true). Set to false for read-only operations on FUSE mounts.
     * @return array{stdout: string, stderr: string, exitCode: int}
     */
    private function execute(Server $server, string $command, int $timeout, string $deploymentLocation, bool $useSudo = true): array
    {
        if ($deploymentLocation === 'local') {
            // Execute locally on phpBorg server
            // Use sudo for privileged operations (mount, etc.)
            // BUT NOT for read-only operations on FUSE mounts (root can't access user FUSE mounts)
            $finalCmd = $useSudo ? "sudo " . $command : $command;
            exec($finalCmd . ' 2>&1', $output, $exitCode);
            return [
                'stdout' => implode("\n", $output),
                'stderr' => '',
                'exitCode' => $exitCode,
            ];
        }

        // Execute remotely via SSH (already uses sudo via SshExecutor)
        return $this->sshExecutor->execute($server, $command, $timeout);
    }

    /**
     * Mount Borg archive using FUSE
     */
    private function mountBorgArchive(Server $server, Archive $archive, string $mountPoint, string $deploymentLocation): void
    {
        $this->logger->info("Mounting Borg archive to {$mountPoint} ({$deploymentLocation})", $server->name);

        // Get repository to access path and passphrase
        $repository = $this->repositoryRepo->findByRepoId($archive->repoId);
        if (!$repository) {
            throw new BackupException("Repository not found for archive {$archive->name}");
        }

        // Create mount point
        $this->execute($server, "mkdir -p {$mountPoint}", 30, $deploymentLocation);

        // Mount archive (Borg FUSE detaches automatically)
        // Use sh -c to set environment variable within sudo context
        $mountCmd = sprintf(
            "sh -c 'BORG_PASSPHRASE=\"%s\" borg mount %s::%s %s'",
            addslashes($repository->passphrase),
            escapeshellarg($repository->repoPath),
            escapeshellarg($archive->name),
            escapeshellarg($mountPoint)
        );

        $result = $this->execute($server, $mountCmd, 60, $deploymentLocation);

        if ($result['exitCode'] !== 0) {
            throw new BackupException("Failed to mount Borg archive: {$result['stdout']}");
        }

        // Wait for mount to be ready (FUSE needs time to initialize)
        sleep(5);

        // Verify mount
        $checkCmd = "mount | grep '{$mountPoint}'";
        $result = $this->execute($server, $checkCmd, 10, $deploymentLocation);

        if ($result['exitCode'] !== 0) {
            throw new BackupException("Borg archive not mounted properly. Mount check output: {$result['stdout']}");
        }

        $this->logger->info("Borg archive mounted successfully", $server->name);
    }


    /**
     * Start database instance on temporary port
     *
     * @return array{pid: int, socket: string|null, connection_string: string}
     */
    private function startDatabaseInstance(Server $server, string $dbType, string $dataDir, int $port, string $deploymentLocation): array
    {
        $this->logger->info("Starting {$dbType} instance on port {$port} ({$deploymentLocation})", $server->name);

        switch ($dbType) {
            case 'postgresql':
            case 'postgres':
                return $this->startPostgreSQL($server, $dataDir, $port, $deploymentLocation);

            case 'mysql':
            case 'mariadb':
                return $this->startMySQL($server, $dataDir, $port, $deploymentLocation);

            case 'mongodb':
                return $this->startMongoDB($server, $dataDir, $port, $deploymentLocation);

            default:
                throw new BackupException("Unsupported database type: {$dbType}");
        }
    }

    /**
     * Start PostgreSQL instance in READ-ONLY mode from backup
     * Uses temporary directories for WAL and temp files
     */
    private function startPostgreSQL(Server $server, string $dataDir, int $port, string $deploymentLocation): array
    {
        $socketDir = "/tmp/phpborg_pg_socket_" . $port;
        $tempDataDir = "/tmp/phpborg_pg_temp_" . $port;

        // Create socket and temp directories
        $this->execute($server, "mkdir -p {$socketDir} && chmod 700 {$socketDir} && chown postgres:postgres {$socketDir}", 30, $deploymentLocation);
        $this->execute($server, "mkdir -p {$tempDataDir} && chmod 700 {$tempDataDir} && chown postgres:postgres {$tempDataDir}", 30, $deploymentLocation);

        // PostgreSQL options for read-only instant recovery:
        // - default_transaction_read_only: Force read-only transactions
        // - fsync=off: No need to sync (temp instance)
        // - full_page_writes=off: No WAL needed
        // - wal_level=minimal: Minimal WAL
        // - max_wal_senders=0: No replication
        // - hot_standby=on: Allow read-only queries
        $pgOptions = sprintf(
            "-p %d -k %s -c listen_addresses=localhost " .
            "-c default_transaction_read_only=on " .
            "-c fsync=off " .
            "-c full_page_writes=off " .
            "-c max_wal_senders=0 " .
            "-c wal_level=minimal " .
            "-c archive_mode=off " .
            "-c temp_file_limit=-1",
            $port,
            escapeshellarg($socketDir)
        );

        // Start PostgreSQL
        $startCmd = sprintf(
            "su - postgres -c \"pg_ctl -D %s -o '%s' -l /tmp/pg_instant_%d.log start\"",
            escapeshellarg($dataDir),
            $pgOptions,
            $port
        );

        $result = $this->execute($server, $startCmd, 60, $deploymentLocation);

        if ($result['exitCode'] !== 0) {
            $errorMsg = !empty($result['stderr']) ? $result['stderr'] : $result['stdout'];
            // Try to get logs
            $logResult = $this->execute($server, "tail -50 /tmp/pg_instant_{$port}.log 2>&1", 10, $deploymentLocation);
            throw new BackupException("Failed to start PostgreSQL: {$errorMsg}\n\nLogs:\n{$logResult['stdout']}");
        }

        // Get PID
        $pidFile = "{$dataDir}/postmaster.pid";
        $pidResult = $this->execute($server, "head -1 {$pidFile}", 10, $deploymentLocation);
        $pid = (int)trim($pidResult['stdout']);

        // Build connection string based on deployment location
        $host = ($deploymentLocation === 'local') ? 'localhost' : $server->hostname;
        $connectionString = "postgresql://{$host}:{$port}/postgres?options=-c%20default_transaction_read_only=on";

        return [
            'pid' => $pid,
            'socket' => $socketDir,
            'connection_string' => $connectionString,
        ];
    }

    /**
     * Start MySQL instance
     */
    private function startMySQL(Server $server, string $dataDir, int $port, string $deploymentLocation): array
    {
        // TODO: Implement MySQL instant recovery
        throw new BackupException("MySQL instant recovery not yet implemented");
    }

    /**
     * Start MongoDB instance
     */
    private function startMongoDB(Server $server, string $dataDir, int $port, string $deploymentLocation): array
    {
        // TODO: Implement MongoDB instant recovery
        throw new BackupException("MongoDB instant recovery not yet implemented");
    }

    /**
     * Stop database instance
     */
    private function stopDatabaseInstance(Server $server, string $dbType, int $pid, string $dataDir, string $deploymentLocation): void
    {
        $this->logger->info("Stopping {$dbType} instance (PID: {$pid}) ({$deploymentLocation})", $server->name);

        switch ($dbType) {
            case 'postgresql':
            case 'postgres':
                // Graceful shutdown
                $this->execute(
                    $server,
                    "su - postgres -c \"pg_ctl -D {$dataDir} stop -m fast\"",
                    30,
                    $deploymentLocation
                );
                break;

            default:
                // Fallback: kill process
                $this->execute($server, "kill {$pid} 2>/dev/null || true", 10, $deploymentLocation);
                break;
        }
    }

    /**
     * Unmount Borg archive
     */
    private function unmountBorgArchive(Server $server, string $mountPoint, string $deploymentLocation): void
    {
        $this->execute($server, "borg umount {$mountPoint} 2>/dev/null || true", 30, $deploymentLocation);
        $this->execute($server, "umount -f {$mountPoint} 2>/dev/null || true", 30, $deploymentLocation);
    }

    /**
     * Cleanup directories
     */
    private function cleanupDirectories(Server $server, array $dirs, string $deploymentLocation): void
    {
        foreach ($dirs as $dir) {
            $this->execute($server, "rm -rf {$dir}", 30, $deploymentLocation);
        }
    }

    /**
     * Find available port for database type
     */
    private function findAvailablePort(string $dbType): int
    {
        $basePort = match ($dbType) {
            'postgresql', 'postgres' => self::BASE_PORT_POSTGRESQL,
            'mysql', 'mariadb' => self::BASE_PORT_MYSQL,
            'mongodb' => self::BASE_PORT_MONGODB,
            default => 10000,
        };

        // Simple increment strategy (could be improved with actual port checking)
        $activeSessions = $this->sessionRepo->findActive();
        $usedPorts = array_map(fn($s) => $s->dbPort, $activeSessions);

        $port = $basePort;
        while (in_array($port, $usedPorts)) {
            $port++;
        }

        return $port;
    }

    /**
     * Find actual data directory in mounted backup
     * Searches for PostgreSQL/MySQL/MongoDB data directories dynamically
     */
    private function findDataDirectoryInMount(Server $server, string $dbType, string $borgMount, string $deploymentLocation): ?string
    {
        switch ($dbType) {
            case 'postgresql':
            case 'postgres':
                // Try common paths: /var/lib/postgresql/*/main, /phpborg/var/lib/postgresql/*/main
                $findCmd = "find {$borgMount} -type d -path '*/var/lib/postgresql/*/main' 2>/dev/null | head -1";
                // Don't use sudo for find - FUSE mounts are user-specific
                $result = $this->execute($server, $findCmd, 30, $deploymentLocation, false);
                $path = trim($result['stdout']);
                return !empty($path) ? $path : null;

            case 'mysql':
            case 'mariadb':
                // Try: /var/lib/mysql, /phpborg/var/lib/mysql
                $findCmd = "find {$borgMount} -type d -path '*/var/lib/mysql' 2>/dev/null | head -1";
                $result = $this->execute($server, $findCmd, 30, $deploymentLocation, false);
                $path = trim($result['stdout']);
                return !empty($path) ? $path : null;

            case 'mongodb':
                // Try: /var/lib/mongodb, /phpborg/var/lib/mongodb
                $findCmd = "find {$borgMount} -type d -path '*/var/lib/mongodb' 2>/dev/null | head -1";
                $result = $this->execute($server, $findCmd, 30, $deploymentLocation, false);
                $path = trim($result['stdout']);
                return !empty($path) ? $path : null;

            default:
                return null;
        }
    }
}
