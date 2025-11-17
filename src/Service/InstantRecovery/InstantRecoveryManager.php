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

        // Generate unique session paths (Docker-based - no overlay needed)
        $sessionId = uniqid('recovery_', true);
        $borgMount = self::BASE_RECOVERY_DIR . "/borg_mount_{$sessionId}";

        // Create session in database
        $recoveryId = $this->sessionRepo->create(
            archiveId: $archive->id,
            serverId: $server->id,
            dbType: $dbType,
            deploymentLocation: $deploymentLocation,
            borgMountPoint: $borgMount,
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
            $this->logger->info("Data directory found: {$dataDir}", $server->name);

            // Step 3: Start database instance in READ-ONLY mode
            // Note: Instant Recovery is read-only - perfect for querying/testing without modifying backup
            $dbInfo = $this->startDatabaseInstance($server, $dbType, $dataDir, $port, $deploymentLocation, $borgMount);

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

            // DEBUG: Disable cleanup to allow manual debugging
            // Attempt cleanup
            /*
            try {
                $session = $this->sessionRepo->findById($recoveryId);
                if ($session) {
                    $this->stopRecovery($session, $server);
                }
            } catch (\Exception $cleanupError) {
                $this->logger->error("Cleanup after failure also failed: {$cleanupError->getMessage()}", $server->name);
            }
            */
            $this->logger->info("DEBUG: Cleanup disabled - mount and temp files preserved for debugging", $server->name);

            throw new BackupException("Failed to start instant recovery: {$e->getMessage()}");
        }
    }

    /**
     * Stop instant recovery session
     *
     * IDEMPOTENT: Tries to cleanup everything. Only marks as stopped if no CRITICAL errors.
     * - If resources already cleaned (idempotent) → marks as stopped
     * - If actual errors (docker rm fails, etc.) → throws exception, session stays active
     */
    public function stopRecovery(InstantRecoverySession $session, Server $server): void
    {
        $this->logger->info("Stopping instant recovery session {$session->id}", $server->name);

        $criticalErrors = [];

        // Best effort cleanup - try everything, collect CRITICAL errors
        try {
            // Stop database instance (always try, even if dbPid is 0 - Docker containers managed by name)
            $this->stopDatabaseInstance($server, $session->dbType, $session->dbPid, $session->deploymentLocation);
        } catch (\Exception $e) {
            $error = "Failed to stop database instance: {$e->getMessage()}";
            $this->logger->error($error, $server->name);
            $criticalErrors[] = $error;
        }

        try {
            // Unmount Borg
            $this->unmountBorgArchive($server, $session->borgMountPoint, $session->deploymentLocation);
        } catch (\Exception $e) {
            $error = "Failed to unmount Borg archive: {$e->getMessage()}";
            $this->logger->error($error, $server->name);
            $criticalErrors[] = $error;
        }

        try {
            // Cleanup directories (never throws - best effort)
            // NOTE: Don't try to rm the mount point itself - only auxiliary directories
            $this->cleanupDirectories($server, [
                "/tmp/phpborg_pg_socket_" . $session->dbPort,
                "/tmp/phpborg_pg_temp_" . $session->dbPort
            ], $session->deploymentLocation);
        } catch (\Exception $e) {
            // Directory cleanup errors are not critical
            $this->logger->warning("Directory cleanup warnings: {$e->getMessage()}", $server->name);
        }

        // Cleanup mount point ONLY if unmount succeeded (no critical errors)
        if (empty($criticalErrors)) {
            try {
                $this->cleanupDirectories($server, [$session->borgMountPoint], $session->deploymentLocation);
            } catch (\Exception $e) {
                $this->logger->warning("Failed to remove mount point directory: {$e->getMessage()}", $server->name);
            }
        }

        // Only mark as stopped if no CRITICAL errors
        if (empty($criticalErrors)) {
            $this->sessionRepo->markStopped($session->id);
            $this->logger->info("Instant recovery session stopped successfully", $server->name);
        } else {
            // CRITICAL errors - session stays active, throw exception
            $errorMsg = "Instant recovery session cleanup failed with critical errors:\n" . implode("\n", $criticalErrors);
            $this->logger->error($errorMsg, $server->name);
            $this->logger->error("Session {$session->id} remains ACTIVE - manual cleanup required", $server->name);
            throw new BackupException($errorMsg);
        }
    }

    /**
     * Execute command either locally or via SSH depending on deployment location
     *
     * @param bool $useSudo Whether to use sudo (default true). Set to false for read-only operations on FUSE mounts.
     * @return array{stdout: string, stderr: string, exitCode: int}
     */
    private function execute(?Server $server, string $command, int $timeout, string $deploymentLocation, bool $useSudo = true): array
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
        if (!$server) {
            throw new BackupException("Server object required for remote execution");
        }
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

        // Create mount point (no sudo needed - /tmp is world-writable)
        $this->execute($server, "mkdir -p {$mountPoint}", 30, $deploymentLocation, false);

        // Mount archive (Borg FUSE detaches automatically)
        // IMPORTANT: MUST be done WITH sudo (root) for allow_other to work correctly
        // Without sudo, phpborg mounts but can't read its own mount (permission denied on subdirs)
        // With sudo, root mounts and allow_other allows phpborg to read
        // HACK ULTIME++: Architecture finale qui MARCHE !
        // Borg mount (sudo) → fuse-overlayfs (phpborg) → chown/chmod merged dir → Docker
        // Note: uid=999,gid=999 options don't work with Borg (ignored), but backup files are already uid 999!
        // Use -o allow_other,allow_root so all users (including phpborg) can access the mount
        $mountCmd = sprintf(
            "sh -c 'BORG_PASSPHRASE=\"%s\" borg mount -o allow_other,allow_root %s::%s %s'",
            addslashes($repository->passphrase),
            escapeshellarg($repository->repoPath),
            escapeshellarg($archive->name),
            escapeshellarg($mountPoint)
        );

        $result = $this->execute($server, $mountCmd, 60, $deploymentLocation, true);

        if ($result['exitCode'] !== 0) {
            throw new BackupException("Failed to mount Borg archive: {$result['stdout']}");
        }

        // Wait for mount to be ready (FUSE needs time to initialize)
        sleep(5);

        // Verify mount (no sudo - checking user's own mount)
        $checkCmd = "mount | grep '{$mountPoint}'";
        $result = $this->execute($server, $checkCmd, 10, $deploymentLocation, false);

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
    private function startDatabaseInstance(Server $server, string $dbType, string $dataDir, int $port, string $deploymentLocation, string $borgMount): array
    {
        $this->logger->info("Starting {$dbType} instance on port {$port} ({$deploymentLocation})", $server->name);

        switch ($dbType) {
            case 'postgresql':
            case 'postgres':
                return $this->startPostgreSQL($server, $dataDir, $port, $deploymentLocation, $borgMount);

            case 'mysql':
            case 'mariadb':
                return $this->startMySQL($server, $dataDir, $port, $deploymentLocation, $borgMount);

            case 'mongodb':
                return $this->startMongoDB($server, $dataDir, $port, $deploymentLocation, $borgMount);

            case 'elasticsearch':
                return $this->startElasticsearch($server, $dataDir, $port, $deploymentLocation, $borgMount);

            default:
                throw new BackupException("Unsupported database type: {$dbType}");
        }
    }

    /**
     * Start PostgreSQL instance in READ-ONLY mode from backup using Docker
     * Clean, portable, and isolated - no system PostgreSQL installation needed
     */
    private function startPostgreSQL(Server $server, string $dataDir, int $port, string $deploymentLocation, string $borgMount): array
    {
        // Detect PostgreSQL version from datadir path
        $version = $this->detectPostgreSQLVersion($dataDir);
        if (!$version) {
            throw new BackupException("Could not detect PostgreSQL version from datadir: {$dataDir}");
        }

        // Ensure Docker is installed (auto-install if missing in local mode)
        if ($deploymentLocation === 'local') {
            $this->ensureDockerInstalled();
        }

        // Container name with unique ID to avoid conflicts
        $containerName = "phpborg_instant_pg_" . uniqid();

        $this->logger->info("Starting PostgreSQL {$version} container '{$containerName}' on port {$port}", $server->name);

        // HACK ULTIME++: Create fuse-overlayfs on top of Borg mount with uid=999
        // Architecture: Borg FUSE (uid=999) → fuse-overlayfs (RW layer) → Docker
        // No bindfs, no NFS - direct and simple!

        $overlayBase = "/tmp/phpborg_overlay_" . uniqid();
        $lowerDir = $dataDir;  // Borg mount with uid=999,gid=999
        $upperDir = "{$overlayBase}/upper";
        $workDir = "{$overlayBase}/work";
        $mergedDir = "{$overlayBase}/merged";

        $this->logger->info("Creating fuse-overlayfs (RW layer) on Borg mount", $server->name);

        // Create overlay directories (no sudo - /tmp is world-writable)
        $this->execute($server, "mkdir -p {$upperDir} {$workDir} {$mergedDir}", 10, $deploymentLocation, false);

        // Mount fuse-overlayfs (WITH sudo - needed to access root-owned Borg mount)
        // Since Borg mount is done with sudo (root-owned), fuse-overlayfs needs sudo too
        // lowerdir = Borg FUSE mount (RO, root-owned with allow_other)
        // upperdir/workdir = tmpfs (RW for PostgreSQL config files)
        $fuseOverlayCmd = sprintf(
            "fuse-overlayfs -o lowerdir=%s -o upperdir=%s -o workdir=%s %s",
            escapeshellarg($lowerDir),
            escapeshellarg($upperDir),
            escapeshellarg($workDir),
            escapeshellarg($mergedDir)
        );
        $this->logger->info("fuse-overlayfs command: {$fuseOverlayCmd}", $server->name);
        $result = $this->execute($server, $fuseOverlayCmd, 30, $deploymentLocation, true);

        if ($result['exitCode'] !== 0) {
            // In local mode, stderr is redirected to stdout (2>&1 in execute()), so check stdout
            $error = !empty($result['stderr']) ? $result['stderr'] : $result['stdout'];
            $this->logger->error("Failed to create fuse-overlayfs (exit {$result['exitCode']}): {$error}", $server->name);
            throw new BackupException("Failed to create fuse-overlayfs: {$error}");
        }

        $this->logger->info("✓ fuse-overlayfs created successfully: {$mergedDir}", $server->name);

        // The merged dir is now the datadir for PostgreSQL (zero-copy instant recovery!)
        // No need to copy anything - fuse-overlayfs provides RW layer on top of RO Borg mount!
        // Any writes (configs, temp files) go to upperdir (tmpfs), original backup stays untouched

        // Create minimal PostgreSQL config files in the merged dir
        // These will be written to the upperdir (tmpfs) thanks to fuse-overlayfs
        $this->logger->info("Creating PostgreSQL config files in overlay", $server->name);

        // Create postgresql.conf using multiple echo commands
        $pgConfPath = "{$mergedDir}/postgresql.conf";
        $pgConfLines = [
            "listen_addresses = '*'",
            "port = {$port}",
            "max_connections = 100",
            "shared_buffers = 128MB",
            "default_transaction_read_only = on",
            "fsync = off",
            "full_page_writes = off",
            "wal_level = minimal",
            "max_wal_senders = 0",
            "archive_mode = off"
        ];

        // Clear file first
        $this->execute($server, "> {$pgConfPath}", 10, $deploymentLocation, false);

        // Append each line
        foreach ($pgConfLines as $line) {
            $cmd = sprintf("echo %s >> %s", escapeshellarg($line), escapeshellarg($pgConfPath));
            $this->execute($server, $cmd, 10, $deploymentLocation, false);
        }

        // Create pg_hba.conf (trust all for instant recovery)
        $hbaPath = "{$mergedDir}/pg_hba.conf";
        $this->execute($server, "> {$hbaPath}", 10, $deploymentLocation, false);
        $this->execute($server, sprintf("echo %s >> %s", escapeshellarg('host all all 0.0.0.0/0 trust'), escapeshellarg($hbaPath)), 10, $deploymentLocation, false);
        $this->execute($server, sprintf("echo %s >> %s", escapeshellarg('host all all ::/0 trust'), escapeshellarg($hbaPath)), 10, $deploymentLocation, false);
        $this->execute($server, sprintf("echo %s >> %s", escapeshellarg('local all all trust'), escapeshellarg($hbaPath)), 10, $deploymentLocation, false);

        // Create pg_ident.conf (empty, but PostgreSQL expects it)
        $identPath = "{$mergedDir}/pg_ident.conf";
        $this->execute($server, "> {$identPath}", 10, $deploymentLocation, false);
        $this->execute($server, sprintf("echo %s >> %s", escapeshellarg('# PostgreSQL User Name Maps'), escapeshellarg($identPath)), 10, $deploymentLocation, false);

        $this->logger->info("✓ Config files created in fuse-overlayfs upper layer", $server->name);

        // NOW fix ownership and permissions on merged dir AFTER creating config files
        // PostgreSQL requires EXACTLY 0700 permissions on datadir (drwx------)
        // and the user running postgres must own the directory AND all files inside
        $this->logger->info("Setting final ownership (999:999) and permissions (0700) on merged dir", $server->name);

        // IMPORTANT: Use chown -R to change ALL files recursively (including config files created above)
        // Config files were created by phpborg/root, must be owned by 999:999 for Docker to read
        $this->execute($server, "chown -R 999:999 {$mergedDir}", 10, $deploymentLocation, true);
        $this->execute($server, "chmod 0700 {$mergedDir}", 10, $deploymentLocation, true);

        // Verify the changes were applied
        $verifyCmd = sprintf("ls -ld %s", escapeshellarg($mergedDir));
        $result = $this->execute($server, $verifyCmd, 10, $deploymentLocation, false);
        $this->logger->info("Merged dir permissions: {$result['stdout']}", $server->name);

        // Docker command - Mount the fuse-overlayfs merged dir with PostgreSQL read-only
        // IMPORTANT: Use --user 999:999 to match Borg mount UID/GID
        // IMPORTANT: Use --entrypoint postgres to bypass the official entrypoint
        $dockerCmd = sprintf(
            "docker run -d " .
            "--name %s " .
            "--user 999:999 " .
            "--net=host " .
            "--entrypoint postgres " .
            "-v %s:/var/lib/postgresql/data:rw " .
            "postgres:%s " .
            "-D /var/lib/postgresql/data -c listen_addresses='*' -c port=%d -c default_transaction_read_only=on",
            escapeshellarg($containerName),
            escapeshellarg($mergedDir),
            escapeshellarg($version),
            $port
        );

        // phpborg user is in docker group, so no sudo needed
        $this->logger->info("Docker command: {$dockerCmd}", $server->name);
        $result = $this->execute($server, $dockerCmd, 120, $deploymentLocation, false);

        if ($result['exitCode'] !== 0) {
            throw new BackupException("Failed to start PostgreSQL container: {$result['stdout']}");
        }

        $containerId = trim($result['stdout']);
        $this->logger->info("Docker run returned container ID: {$containerId}", $server->name);

        // Wait for PostgreSQL to be ready
        $this->logger->info("Waiting for PostgreSQL to be ready...", $server->name);
        sleep(5);

        // Verify container is still running (no sudo needed)
        $checkCmd = "docker ps --filter id={$containerId} --format '{{.ID}}'";
        $result = $this->execute($server, $checkCmd, 10, $deploymentLocation, false);
        $runningId = trim($result['stdout']);

        if (empty($runningId)) {
            // Container stopped - get logs before it's removed by --rm
            $logsCmd = "docker logs {$containerId} 2>&1";
            $logsResult = $this->execute($server, $logsCmd, 10, $deploymentLocation, false);
            throw new BackupException("PostgreSQL container failed to start. Logs:\n{$logsResult['stdout']}");
        }

        $this->logger->info("PostgreSQL container started successfully (ID: {$containerId})", $server->name);

        // Build connection string based on deployment location
        $host = ($deploymentLocation === 'local') ? '127.0.0.1' : $server->hostname;
        $connectionString = "postgresql://{$host}:{$port}/postgres";

        return [
            'pid' => 0, // No PID needed - Docker manages it
            'socket' => null, // TCP connection only
            'connection_string' => $connectionString,
            'container_id' => $containerId,
            'container_name' => $containerName,
        ];
    }

    /**
     * Start MySQL/MariaDB instance in READ-ONLY mode from backup using Docker
     */
    private function startMySQL(Server $server, string $dataDir, int $port, string $deploymentLocation, string $borgMount): array
    {
        // Detect MySQL/MariaDB version from datadir
        $version = $this->detectMySQLVersion($server, $dataDir, $deploymentLocation);
        if (!$version) {
            throw new BackupException("Could not detect MySQL/MariaDB version from datadir: {$dataDir}");
        }

        // Determine if it's MySQL or MariaDB
        $isMariaDB = $this->isMySQLMariaDB($server, $dataDir, $deploymentLocation);
        $image = $isMariaDB ? "mariadb:{$version}" : "mysql:{$version}";

        // Container name with unique ID
        $containerName = "phpborg_instant_mysql_" . uniqid();

        $this->logger->info("Starting {$image} container '{$containerName}' on port {$port}", $server->name);

        // MySQL/MariaDB read-only configuration
        // Note: MySQL doesn't have a simple --read-only flag like PostgreSQL
        // We use --innodb-read-only=1 and --read_only=1 (global variables)
        $dockerCmd = sprintf(
            "docker run -d " .
            "--name %s " .
            "--user 999:999 " .
            "--net=host " .
            "-v %s:/var/lib/mysql:rw " .
            "-e MYSQL_ALLOW_EMPTY_PASSWORD=1 " .
            "%s " .
            "--port=%d " .
            "--read_only=1 " .
            "--innodb-read-only=1 " .
            "--skip-log-bin",
            escapeshellarg($containerName),
            escapeshellarg($dataDir),
            escapeshellarg($image),
            $port
        );

        $this->logger->info("Docker command: {$dockerCmd}", $server->name);
        $result = $this->execute($server, $dockerCmd, 120, $deploymentLocation, false);

        if ($result['exitCode'] !== 0) {
            throw new BackupException("Failed to start MySQL container: {$result['stdout']}");
        }

        $containerId = trim($result['stdout']);
        $this->logger->info("MySQL container started (ID: {$containerId})", $server->name);

        // Wait for MySQL to be ready
        sleep(5);

        // Verify container is running
        $checkCmd = "docker ps --filter id={$containerId} --format '{{.ID}}'";
        $result = $this->execute($server, $checkCmd, 10, $deploymentLocation, false);

        if (empty(trim($result['stdout']))) {
            $logsCmd = "docker logs {$containerId} 2>&1";
            $logsResult = $this->execute($server, $logsCmd, 10, $deploymentLocation, false);
            throw new BackupException("MySQL container failed to start. Logs:\n{$logsResult['stdout']}");
        }

        // Build connection string
        $host = ($deploymentLocation === 'local') ? '127.0.0.1' : $server->hostname;
        $connectionString = "mysql://root@{$host}:{$port}/mysql";

        return [
            'pid' => 0,
            'socket' => null,
            'connection_string' => $connectionString,
            'container_id' => $containerId,
            'container_name' => $containerName,
        ];
    }

    /**
     * Start MongoDB instance in READ-ONLY mode from backup using Docker
     */
    private function startMongoDB(Server $server, string $dataDir, int $port, string $deploymentLocation, string $borgMount): array
    {
        // Detect MongoDB version
        $version = $this->detectMongoDBVersion($server, $dataDir, $deploymentLocation);
        if (!$version) {
            throw new BackupException("Could not detect MongoDB version from datadir: {$dataDir}");
        }

        // Container name with unique ID
        $containerName = "phpborg_instant_mongo_" . uniqid();

        $this->logger->info("Starting mongo:{$version} container '{$containerName}' on port {$port}", $server->name);

        // MongoDB doesn't have built-in read-only mode at startup level
        // But we can:
        // 1. Start with --nojournal and --notablescan for safety
        // 2. Users can connect and use db.fsyncLock() for read-only if needed
        // 3. The datadir is on read-only FUSE mount anyway
        $dockerCmd = sprintf(
            "docker run -d " .
            "--name %s " .
            "--user 999:999 " .
            "--net=host " .
            "-v %s:/data/db:rw " .
            "mongo:%s " .
            "--port %d " .
            "--nojournal " .
            "--bind_ip_all",
            escapeshellarg($containerName),
            escapeshellarg($dataDir),
            escapeshellarg($version),
            $port
        );

        $this->logger->info("Docker command: {$dockerCmd}", $server->name);
        $result = $this->execute($server, $dockerCmd, 120, $deploymentLocation, false);

        if ($result['exitCode'] !== 0) {
            throw new BackupException("Failed to start MongoDB container: {$result['stdout']}");
        }

        $containerId = trim($result['stdout']);
        $this->logger->info("MongoDB container started (ID: {$containerId})", $server->name);

        // Wait for MongoDB to be ready
        sleep(5);

        // Verify container is running
        $checkCmd = "docker ps --filter id={$containerId} --format '{{.ID}}'";
        $result = $this->execute($server, $checkCmd, 10, $deploymentLocation, false);

        if (empty(trim($result['stdout']))) {
            $logsCmd = "docker logs {$containerId} 2>&1";
            $logsResult = $this->execute($server, $logsCmd, 10, $deploymentLocation, false);
            throw new BackupException("MongoDB container failed to start. Logs:\n{$logsResult['stdout']}");
        }

        // Build connection string
        $host = ($deploymentLocation === 'local') ? '127.0.0.1' : $server->hostname;
        $connectionString = "mongodb://{$host}:{$port}/";

        return [
            'pid' => 0,
            'socket' => null,
            'connection_string' => $connectionString,
            'container_id' => $containerId,
            'container_name' => $containerName,
        ];
    }

    /**
     * Start Elasticsearch instance in READ-ONLY mode from backup using Docker
     */
    private function startElasticsearch(Server $server, string $dataDir, int $port, string $deploymentLocation, string $borgMount): array
    {
        // Detect Elasticsearch version
        $version = $this->detectElasticsearchVersion($server, $dataDir, $deploymentLocation);
        if (!$version) {
            throw new BackupException("Could not detect Elasticsearch version from datadir: {$dataDir}");
        }

        // Container name with unique ID
        $containerName = "phpborg_instant_es_" . uniqid();

        $this->logger->info("Starting elasticsearch:{$version} container '{$containerName}' on ports {$port}(HTTP) / " . ($port + 100) . "(transport)", $server->name);

        // Elasticsearch needs two ports: HTTP (9200) and transport (9300)
        $httpPort = $port;
        $transportPort = $port + 100; // Offset by 100 to avoid conflicts

        // Elasticsearch Docker configuration
        // Single-node mode, no cluster, read-only snapshot repository
        $dockerCmd = sprintf(
            "docker run -d " .
            "--name %s " .
            "--user 1000:1000 " .
            "--net=host " .
            "-v %s:/usr/share/elasticsearch/data:rw " .
            "-e discovery.type=single-node " .
            "-e xpack.security.enabled=false " .
            "-e http.port=%d " .
            "-e transport.port=%d " .
            "-e cluster.routing.allocation.disk.threshold_enabled=false " .
            "elasticsearch:%s",
            escapeshellarg($containerName),
            escapeshellarg($dataDir),
            $httpPort,
            $transportPort,
            escapeshellarg($version)
        );

        $this->logger->info("Docker command: {$dockerCmd}", $server->name);
        $result = $this->execute($server, $dockerCmd, 120, $deploymentLocation, false);

        if ($result['exitCode'] !== 0) {
            throw new BackupException("Failed to start Elasticsearch container: {$result['stdout']}");
        }

        $containerId = trim($result['stdout']);
        $this->logger->info("Elasticsearch container started (ID: {$containerId})", $server->name);

        // Wait for Elasticsearch to be ready (takes longer than other DBs)
        $this->logger->info("Waiting for Elasticsearch to be ready (may take 10-30s)...", $server->name);
        sleep(10);

        // Verify container is running
        $checkCmd = "docker ps --filter id={$containerId} --format '{{.ID}}'";
        $result = $this->execute($server, $checkCmd, 10, $deploymentLocation, false);

        if (empty(trim($result['stdout']))) {
            $logsCmd = "docker logs {$containerId} 2>&1 | tail -50";
            $logsResult = $this->execute($server, $logsCmd, 10, $deploymentLocation, false);
            throw new BackupException("Elasticsearch container failed to start. Last 50 lines:\n{$logsResult['stdout']}");
        }

        // Build connection string
        $host = ($deploymentLocation === 'local') ? '127.0.0.1' : $server->hostname;
        $connectionString = "http://{$host}:{$httpPort}";

        return [
            'pid' => 0,
            'socket' => null,
            'connection_string' => $connectionString,
            'container_id' => $containerId,
            'container_name' => $containerName,
        ];
    }

    /**
     * Stop database instance (Docker container) - idempotent
     */
    private function stopDatabaseInstance(Server $server, string $dbType, int $pid, string $deploymentLocation): void
    {
        $this->logger->info("Stopping {$dbType} instance ({$deploymentLocation})", $server->name);

        // For Docker-based instances, we stop AND REMOVE by container name pattern
        // Format: phpborg_instant_pg_{port} or phpborg_instant_mysql_{port}

        switch ($dbType) {
            case 'postgresql':
            case 'postgres':
                // Stop and remove all PostgreSQL Docker containers matching our pattern
                $listCmd = "docker ps -a --filter name=phpborg_instant_pg_ --format '{{.Names}}'";
                $result = $this->execute($server, $listCmd, 10, $deploymentLocation, false);
                $containers = array_filter(explode("\n", trim($result['stdout'])));

                if (empty($containers)) {
                    $this->logger->info("No PostgreSQL containers found (already stopped)", $server->name);
                    return;
                }

                foreach ($containers as $containerName) {
                    $this->logger->info("Stopping & removing Docker container: {$containerName}", $server->name);
                    // phpborg is in docker group, no sudo needed
                    $stopResult = $this->execute($server, "docker stop {$containerName}", 30, $deploymentLocation, false);
                    if ($stopResult['exitCode'] !== 0) {
                        // Container might already be stopped - check if it's just a "not running" error
                        $this->logger->warning("docker stop returned {$stopResult['exitCode']} (might already be stopped)", $server->name);
                    }

                    // Wait a moment for Docker to fully release all mount references
                    sleep(2);

                    $rmResult = $this->execute($server, "docker rm {$containerName}", 30, $deploymentLocation, false);
                    if ($rmResult['exitCode'] !== 0) {
                        $error = !empty($rmResult['stderr']) ? $rmResult['stderr'] : $rmResult['stdout'];
                        throw new BackupException("Failed to remove Docker container {$containerName}: {$error}");
                    }
                }
                break;

            case 'mysql':
            case 'mariadb':
                // Stop and remove MySQL Docker containers
                $listCmd = "docker ps -a --filter name=phpborg_instant_mysql_ --format '{{.Names}}'";
                $result = $this->execute($server, $listCmd, 10, $deploymentLocation, false);
                $containers = array_filter(explode("\n", trim($result['stdout'])));

                if (empty($containers)) {
                    $this->logger->info("No MySQL containers found (already stopped)", $server->name);
                    return;
                }

                foreach ($containers as $containerName) {
                    $this->logger->info("Stopping & removing Docker container: {$containerName}", $server->name);
                    // phpborg is in docker group, no sudo needed
                    $stopResult = $this->execute($server, "docker stop {$containerName}", 30, $deploymentLocation, false);
                    if ($stopResult['exitCode'] !== 0) {
                        $this->logger->warning("docker stop returned {$stopResult['exitCode']} (might already be stopped)", $server->name);
                    }

                    // Wait a moment for Docker to fully release all mount references
                    sleep(2);

                    $rmResult = $this->execute($server, "docker rm {$containerName}", 30, $deploymentLocation, false);
                    if ($rmResult['exitCode'] !== 0) {
                        $error = !empty($rmResult['stderr']) ? $rmResult['stderr'] : $rmResult['stdout'];
                        throw new BackupException("Failed to remove Docker container {$containerName}: {$error}");
                    }
                }
                break;

            case 'mongodb':
                // Stop and remove MongoDB Docker containers
                $listCmd = "docker ps -a --filter name=phpborg_instant_mongo_ --format '{{.Names}}'";
                $result = $this->execute($server, $listCmd, 10, $deploymentLocation, false);
                $containers = array_filter(explode("\n", trim($result['stdout'])));

                if (empty($containers)) {
                    $this->logger->info("No MongoDB containers found (already stopped)", $server->name);
                    return;
                }

                foreach ($containers as $containerName) {
                    $this->logger->info("Stopping & removing Docker container: {$containerName}", $server->name);
                    $stopResult = $this->execute($server, "docker stop {$containerName}", 30, $deploymentLocation, false);
                    if ($stopResult['exitCode'] !== 0) {
                        $this->logger->warning("docker stop returned {$stopResult['exitCode']} (might already be stopped)", $server->name);
                    }

                    sleep(2);

                    $rmResult = $this->execute($server, "docker rm {$containerName}", 30, $deploymentLocation, false);
                    if ($rmResult['exitCode'] !== 0) {
                        $error = !empty($rmResult['stderr']) ? $rmResult['stderr'] : $rmResult['stdout'];
                        throw new BackupException("Failed to remove Docker container {$containerName}: {$error}");
                    }
                }
                break;

            case 'elasticsearch':
                // Stop and remove Elasticsearch Docker containers
                $listCmd = "docker ps -a --filter name=phpborg_instant_es_ --format '{{.Names}}'";
                $result = $this->execute($server, $listCmd, 10, $deploymentLocation, false);
                $containers = array_filter(explode("\n", trim($result['stdout'])));

                if (empty($containers)) {
                    $this->logger->info("No Elasticsearch containers found (already stopped)", $server->name);
                    return;
                }

                foreach ($containers as $containerName) {
                    $this->logger->info("Stopping & removing Docker container: {$containerName}", $server->name);
                    $stopResult = $this->execute($server, "docker stop {$containerName}", 30, $deploymentLocation, false);
                    if ($stopResult['exitCode'] !== 0) {
                        $this->logger->warning("docker stop returned {$stopResult['exitCode']} (might already be stopped)", $server->name);
                    }

                    sleep(2);

                    $rmResult = $this->execute($server, "docker rm {$containerName}", 30, $deploymentLocation, false);
                    if ($rmResult['exitCode'] !== 0) {
                        $error = !empty($rmResult['stderr']) ? $rmResult['stderr'] : $rmResult['stdout'];
                        throw new BackupException("Failed to remove Docker container {$containerName}: {$error}");
                    }
                }
                break;

            default:
                // Fallback: kill process if PID exists
                if ($pid) {
                    $killResult = $this->execute($server, "kill {$pid}", 10, $deploymentLocation);
                    if ($killResult['exitCode'] !== 0) {
                        // Process might already be dead - that's ok
                        $this->logger->warning("kill returned {$killResult['exitCode']} (process might already be dead)", $server->name);
                    }
                }
                break;
        }
    }

    /**
     * Unmount Borg archive and overlayfs - idempotent
     */
    private function unmountBorgArchive(Server $server, string $mountPoint, string $deploymentLocation): void
    {
        $this->logger->info("Unmounting Borg archive and overlayfs from {$mountPoint}", $server->name);

        // IMPORTANT: Since we mounted with sudo, we MUST unmount with sudo
        // Architecture: Borg mount (sudo) → fuse-overlayfs (sudo) → chown → Docker

        // 1. First, check and unmount any overlayfs mounts (they would be on /tmp/phpborg_overlay_*)
        // Get all overlay mount points (in /tmp, not in the mount directory)
        $findCmd = "find /tmp -maxdepth 1 -type d -name 'phpborg_overlay_*'";
        $result = $this->execute($server, $findCmd, 10, $deploymentLocation, false);
        $overlayDirs = array_filter(explode("\n", trim($result['stdout'])));

        foreach ($overlayDirs as $overlayDir) {
            // Try to unmount overlay merged dir
            $mergedDir = $overlayDir . "/merged";
            $this->logger->info("Unmounting overlay: {$mergedDir}", $server->name);

            $umountResult = $this->execute($server, "umount {$mergedDir}", 30, $deploymentLocation, true);
            if ($umountResult['exitCode'] !== 0) {
                $this->logger->warning("Failed to unmount overlay {$mergedDir} (might already be unmounted)", $server->name);
            }

            // Cleanup overlay dirs
            $rmResult = $this->execute($server, "rm -rf {$overlayDir}", 30, $deploymentLocation, true);
            if ($rmResult['exitCode'] !== 0) {
                $this->logger->warning("Failed to remove overlay dir {$overlayDir}", $server->name);
            }
        }

        // 2. Check if mount point is actually mounted
        $checkMountCmd = "mount | grep -q " . escapeshellarg($mountPoint);
        $checkResult = $this->execute($server, $checkMountCmd, 10, $deploymentLocation, false);

        if ($checkResult['exitCode'] !== 0) {
            // Not mounted - that's fine (idempotent)
            $this->logger->info("Borg mount point not found (already unmounted): {$mountPoint}", $server->name);
            return;
        }

        // 3. Then unmount the Borg FUSE mount (must use sudo umount, not borg umount)
        $this->logger->info("Unmounting Borg FUSE: {$mountPoint}", $server->name);
        $umountResult = $this->execute($server, "umount {$mountPoint}", 30, $deploymentLocation, true);

        if ($umountResult['exitCode'] !== 0) {
            // Try fallback fusermount if umount fails
            $umountError = !empty($umountResult['stderr']) ? $umountResult['stderr'] : $umountResult['stdout'];
            $this->logger->warning("umount failed (exit {$umountResult['exitCode']}), trying fusermount: {$umountError}", $server->name);
            $fusermountResult = $this->execute($server, "fusermount -u {$mountPoint}", 30, $deploymentLocation, true);

            if ($fusermountResult['exitCode'] !== 0) {
                // Last resort: lazy unmount (umount -l) - unmounts when no longer busy
                $fusermountError = !empty($fusermountResult['stderr']) ? $fusermountResult['stderr'] : $fusermountResult['stdout'];
                $this->logger->warning("fusermount failed, trying lazy unmount: {$fusermountError}", $server->name);

                $lazyResult = $this->execute($server, "umount -l {$mountPoint}", 30, $deploymentLocation, true);
                if ($lazyResult['exitCode'] !== 0) {
                    $lazyError = !empty($lazyResult['stderr']) ? $lazyResult['stderr'] : $lazyResult['stdout'];
                    throw new BackupException("Failed to unmount Borg FUSE at {$mountPoint}: umount exit={$umountResult['exitCode']} ('{$umountError}'), fusermount exit={$fusermountResult['exitCode']} ('{$fusermountError}'), lazy unmount exit={$lazyResult['exitCode']} ('{$lazyError}')");
                }

                $this->logger->info("Lazy unmount scheduled (will complete when mount no longer busy)", $server->name);
            }
        }

        $this->logger->info("Borg FUSE unmounted successfully", $server->name);
    }

    /**
     * Cleanup directories (idempotent - doesn't fail if dirs don't exist)
     */
    private function cleanupDirectories(Server $server, array $dirs, string $deploymentLocation): void
    {
        foreach ($dirs as $dir) {
            if (empty($dir) || $dir === '/' || $dir === '/tmp') {
                $this->logger->warning("Skipping dangerous directory cleanup: {$dir}", $server->name);
                continue;
            }

            // Check if directory exists first
            $checkCmd = "test -e {$dir}";
            $checkResult = $this->execute($server, $checkCmd, 10, $deploymentLocation, false);

            if ($checkResult['exitCode'] !== 0) {
                // Directory doesn't exist - that's fine (idempotent)
                $this->logger->info("Directory already removed or doesn't exist: {$dir}", $server->name);
                continue;
            }

            $this->logger->info("Cleaning up directory: {$dir}", $server->name);
            $result = $this->execute($server, "rm -rf {$dir}", 30, $deploymentLocation, true);

            if ($result['exitCode'] !== 0) {
                $error = !empty($result['stderr']) ? $result['stderr'] : $result['stdout'];
                $this->logger->warning("Failed to cleanup directory {$dir}: {$error}", $server->name);
                // Don't throw - just log warning (best effort cleanup)
            }
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
     * Uses multiple detection strategies for robustness
     */
    private function findDataDirectoryInMount(Server $server, string $dbType, string $borgMount, string $deploymentLocation): ?string
    {
        $this->logger->info("Searching for {$dbType} datadir in mount: {$borgMount}", $server->name);

        // First, verify mount is accessible and list contents
        // IMPORTANT: Use sudo because Borg mount is root-owned
        $lsCmd = "ls -la " . escapeshellarg($borgMount);
        $lsResult = $this->execute($server, $lsCmd, 10, $deploymentLocation, true);
        $this->logger->info("Mount listing (exit: {$lsResult['exitCode']}): {$lsResult['stdout']}", $server->name);

        if ($lsResult['exitCode'] !== 0 || empty(trim($lsResult['stdout']))) {
            $this->logger->error("Mount appears empty or inaccessible", $server->name);
            return null;
        }

        switch ($dbType) {
            case 'postgresql':
            case 'postgres':
                return $this->findPostgreSQLDataDir($server, $borgMount, $deploymentLocation);

            case 'mysql':
            case 'mariadb':
                return $this->findMySQLDataDir($server, $borgMount, $deploymentLocation);

            case 'mongodb':
                return $this->findMongoDBDataDir($server, $borgMount, $deploymentLocation);

            default:
                return null;
        }
    }

    /**
     * Find PostgreSQL data directory using multiple strategies
     */
    private function findPostgreSQLDataDir(Server $server, string $borgMount, string $deploymentLocation): ?string
    {
        // Strategy 1: Try common direct paths
        $commonPaths = [
            '/var/lib/postgresql/12/main',
            '/var/lib/postgresql/13/main',
            '/var/lib/postgresql/14/main',
            '/var/lib/postgresql/15/main',
            '/var/lib/postgresql/16/main',
            '/var/lib/postgresql/11/main',
            '/var/lib/postgresql/10/main',
        ];

        foreach ($commonPaths as $relativePath) {
            $fullPath = $borgMount . $relativePath;
            $testCmd = "test -d " . escapeshellarg($fullPath) . " && echo 'EXISTS'";
            $result = $this->execute($server, $testCmd, 10, $deploymentLocation, false);

            if (trim($result['stdout']) === 'EXISTS') {
                $this->logger->info("Found PostgreSQL datadir (direct path): {$fullPath}", $server->name);
                return $fullPath;
            }
        }

        // Strategy 2: Look for LVM snapshot structure (phpborg/{server}/pgsql/var/lib/postgresql/*/main)
        $findLvmCmd = sprintf(
            "find %s -type d -name 'main' -path '*/phpborg/*/pgsql/var/lib/postgresql/*' 2>/dev/null | head -1",
            escapeshellarg($borgMount)
        );
        $this->logger->info("Searching for LVM snapshot PostgreSQL datadir: {$findLvmCmd}", $server->name);
        $result = $this->execute($server, $findLvmCmd, 30, $deploymentLocation, false);
        $path = trim($result['stdout']);

        if (!empty($path)) {
            $this->logger->info("Found PostgreSQL datadir (LVM snapshot): {$path}", $server->name);
            return $path;
        }

        // Strategy 3: Generic find with escaped mount path
        $findCmd = sprintf(
            "find %s -type d -name 'main' -path '*/postgresql/*' 2>/dev/null | head -1",
            escapeshellarg($borgMount)
        );
        $this->logger->info("Trying generic find command: {$findCmd}", $server->name);
        $result = $this->execute($server, $findCmd, 30, $deploymentLocation, false);
        $path = trim($result['stdout']);

        if (!empty($path)) {
            $this->logger->info("Found PostgreSQL datadir (generic find): {$path}", $server->name);
            return $path;
        }

        // Strategy 4: List var/lib/postgresql and find version directories
        $lsCmd = "ls " . escapeshellarg($borgMount . '/var/lib/postgresql') . " 2>/dev/null";
        $lsResult = $this->execute($server, $lsCmd, 10, $deploymentLocation, false);
        $this->logger->info("PostgreSQL versions found: {$lsResult['stdout']}", $server->name);

        if ($lsResult['exitCode'] === 0 && !empty(trim($lsResult['stdout']))) {
            $versions = array_filter(explode("\n", trim($lsResult['stdout'])));
            foreach ($versions as $version) {
                $version = trim($version);
                if (is_numeric($version)) {
                    $mainPath = $borgMount . "/var/lib/postgresql/{$version}/main";
                    $testCmd = "test -d " . escapeshellarg($mainPath) . " && echo 'EXISTS'";
                    $testResult = $this->execute($server, $testCmd, 10, $deploymentLocation, false);

                    if (trim($testResult['stdout']) === 'EXISTS') {
                        $this->logger->info("Found PostgreSQL datadir (ls strategy): {$mainPath}", $server->name);
                        return $mainPath;
                    }
                }
            }
        }

        $this->logger->error("Could not find PostgreSQL datadir after trying all strategies", $server->name);
        return null;
    }

    /**
     * Find MySQL data directory
     */
    private function findMySQLDataDir(Server $server, string $borgMount, string $deploymentLocation): ?string
    {
        // Strategy 1: Try direct /var/lib/mysql path (full system backups)
        $commonPath = $borgMount . '/var/lib/mysql';
        $testCmd = "test -d " . escapeshellarg($commonPath) . " && echo 'EXISTS'";
        $result = $this->execute($server, $testCmd, 10, $deploymentLocation, false);

        if (trim($result['stdout']) === 'EXISTS') {
            $this->logger->info("Found MySQL datadir (direct path): {$commonPath}", $server->name);
            return $commonPath;
        }

        // Strategy 2: Look for LVM snapshot structure (phpborg/{server}/mysql/var/lib/mysql)
        $findLvmCmd = sprintf(
            "find %s -type d -path '*/phpborg/*/mysql/var/lib/mysql' 2>/dev/null | head -1",
            escapeshellarg($borgMount)
        );
        $this->logger->info("Searching for LVM snapshot MySQL datadir: {$findLvmCmd}", $server->name);
        $result = $this->execute($server, $findLvmCmd, 30, $deploymentLocation, false);
        $path = trim($result['stdout']);

        if (!empty($path)) {
            $this->logger->info("Found MySQL datadir (LVM snapshot): {$path}", $server->name);
            return $path;
        }

        // Strategy 3: Generic find for any mysql directory in var/lib
        $findCmd = sprintf(
            "find %s -type d -name 'mysql' -path '*/var/lib/*' 2>/dev/null | head -1",
            escapeshellarg($borgMount)
        );
        $result = $this->execute($server, $findCmd, 30, $deploymentLocation, false);
        $path = trim($result['stdout']);

        if (!empty($path)) {
            $this->logger->info("Found MySQL datadir (generic find): {$path}", $server->name);
            return $path;
        }

        $this->logger->error("Could not find MySQL datadir after trying all strategies", $server->name);
        return null;
    }

    /**
     * Find MongoDB data directory
     */
    private function findMongoDBDataDir(Server $server, string $borgMount, string $deploymentLocation): ?string
    {
        // Strategy 1: Try direct /var/lib/mongodb path (full system backups)
        $commonPath = $borgMount . '/var/lib/mongodb';
        $testCmd = "test -d " . escapeshellarg($commonPath) . " && echo 'EXISTS'";
        $result = $this->execute($server, $testCmd, 10, $deploymentLocation, false);

        if (trim($result['stdout']) === 'EXISTS') {
            $this->logger->info("Found MongoDB datadir (direct path): {$commonPath}", $server->name);
            return $commonPath;
        }

        // Strategy 2: Look for LVM snapshot structure (phpborg/{server}/mongodb/var/lib/mongodb)
        $findLvmCmd = sprintf(
            "find %s -type d -path '*/phpborg/*/mongodb/var/lib/mongodb' 2>/dev/null | head -1",
            escapeshellarg($borgMount)
        );
        $this->logger->info("Searching for LVM snapshot MongoDB datadir: {$findLvmCmd}", $server->name);
        $result = $this->execute($server, $findLvmCmd, 30, $deploymentLocation, false);
        $path = trim($result['stdout']);

        if (!empty($path)) {
            $this->logger->info("Found MongoDB datadir (LVM snapshot): {$path}", $server->name);
            return $path;
        }

        // Strategy 3: Generic find for any mongodb directory in var/lib
        $findCmd = sprintf(
            "find %s -type d -name 'mongodb' -path '*/var/lib/*' 2>/dev/null | head -1",
            escapeshellarg($borgMount)
        );
        $result = $this->execute($server, $findCmd, 30, $deploymentLocation, false);
        $path = trim($result['stdout']);

        if (!empty($path)) {
            $this->logger->info("Found MongoDB datadir (generic find): {$path}", $server->name);
            return $path;
        }

        $this->logger->error("Could not find MongoDB datadir after trying all strategies", $server->name);
        return null;
    }

    /**
     * Detect PostgreSQL version from datadir path
     * Example: /var/lib/postgresql/12/main -> 12
     */
    private function detectPostgreSQLVersion(string $dataDir): ?string
    {
        if (preg_match('#/postgresql/(\d+)/#', $dataDir, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Detect MySQL/MariaDB version from datadir
     */
    private function detectMySQLVersion(Server $server, string $dataDir, string $deploymentLocation): ?string
    {
        // Check for mysql_upgrade_info file which contains version
        $versionFile = $dataDir . '/mysql_upgrade_info';
        $checkCmd = "test -f {$versionFile} && cat {$versionFile}";
        $result = $this->execute($server, $checkCmd, 10, $deploymentLocation, false);

        if ($result['exitCode'] === 0 && !empty($result['stdout'])) {
            $version = trim($result['stdout']);
            // Extract major.minor version (e.g., "8.0.32-0ubuntu0.20.04.2" -> "8.0")
            if (preg_match('/^(\d+\.\d+)/', $version, $matches)) {
                return $matches[1];
            }
        }

        // Fallback: Try to detect from directory structure or files
        // MariaDB often has mysql_upgrade_info with "10.x.x-MariaDB"
        // MySQL has it with "8.0.x" or "5.7.x"
        // If all fails, use a reasonable default
        return '8.0'; // Default to MySQL 8.0
    }

    /**
     * Determine if it's MariaDB or MySQL
     */
    private function isMySQLMariaDB(Server $server, string $dataDir, string $deploymentLocation): bool
    {
        $versionFile = $dataDir . '/mysql_upgrade_info';
        $checkCmd = "test -f {$versionFile} && cat {$versionFile}";
        $result = $this->execute($server, $checkCmd, 10, $deploymentLocation, false);

        if ($result['exitCode'] === 0 && !empty($result['stdout'])) {
            $version = trim($result['stdout']);
            return stripos($version, 'mariadb') !== false;
        }

        return false; // Default to MySQL if unsure
    }

    /**
     * Detect MongoDB version from datadir
     */
    private function detectMongoDBVersion(Server $server, string $dataDir, string $deploymentLocation): ?string
    {
        // MongoDB stores version in WiredTiger.wt or in storage.bson metadata
        // Try to find WiredTiger.wt which contains version info
        $wtFile = $dataDir . '/WiredTiger.wt';
        $checkCmd = "test -f {$wtFile}";
        $result = $this->execute($server, $checkCmd, 10, $deploymentLocation, false);

        if ($result['exitCode'] === 0) {
            // WiredTiger exists - likely MongoDB 3.x+
            // Check for version in mongod.lock or diagnostic.data
            $lockFile = $dataDir . '/mongod.lock';
            // If we can't detect exact version, use reasonable defaults based on WiredTiger presence
            // WiredTiger = MongoDB 3.0+, most common is 4.x, 5.x, 6.x
            return '6.0'; // Default to recent stable version
        }

        // Fallback: older MongoDB (2.x) with MMAPv1
        return '4.4'; // Conservative default
    }

    /**
     * Detect Elasticsearch version from datadir
     */
    private function detectElasticsearchVersion(Server $server, string $dataDir, string $deploymentLocation): ?string
    {
        // Elasticsearch stores version in nodes/0/_state/node-*.st files or in segments_* files
        // Check for any node metadata that might contain version
        $findCmd = "find {$dataDir}/nodes -name 'node-*.st' 2>/dev/null | head -1";
        $result = $this->execute($server, $findCmd, 10, $deploymentLocation, false);

        // If we found metadata files, we know it's likely ES 5.x+
        // Exact version detection from binary files is complex, use reasonable defaults
        if ($result['exitCode'] === 0 && !empty(trim($result['stdout']))) {
            return '8.0'; // Recent stable version
        }

        // Check for _state directory (ES 2.x+)
        $checkCmd = "test -d {$dataDir}/nodes/0/_state";
        $result = $this->execute($server, $checkCmd, 10, $deploymentLocation, false);

        if ($result['exitCode'] === 0) {
            return '7.17'; // Conservative default for recent versions
        }

        return '7.10'; // Very conservative default
    }

    /**
     * Ensure Docker is installed on backup server (local mode only)
     * Auto-installs docker.io package if missing
     * Adds phpborg user to docker group for non-root access
     */
    private function ensureDockerInstalled(): void
    {
        $this->logger->info("Checking if Docker is installed locally");

        // Check if Docker is installed (check exit code, not stdout content)
        $checkCmd = "docker --version 2>/dev/null";
        $result = $this->execute(null, $checkCmd, 10, 'local', false);

        if ($result['exitCode'] === 0) {
            $this->logger->info("Docker already installed: " . trim($result['stdout']));
            // Ensure phpborg is in docker group
            $this->execute(null, "usermod -aG docker phpborg 2>/dev/null || true", 10, 'local', true);
            return;
        }

        $this->logger->info("Docker not found, installing docker.io package...");

        // Step 1: Update package list
        $this->execute(null, "apt-get update -qq", 120, 'local', true);

        // Step 2: Install Docker (non-interactive)
        $this->logger->info("Installing docker.io... (this may take a few minutes)");
        $installCmd = "DEBIAN_FRONTEND=noninteractive apt-get install -y -qq docker.io";
        $installResult = $this->execute(null, $installCmd, 600, 'local', true);

        if ($installResult['exitCode'] !== 0) {
            throw new BackupException("Failed to install Docker: {$installResult['stdout']}");
        }

        // Step 3: Start Docker service
        $this->execute(null, "systemctl start docker", 30, 'local', true);
        $this->execute(null, "systemctl enable docker", 30, 'local', true);

        // Step 4: Add phpborg user to docker group (allows non-root docker commands)
        $this->execute(null, "usermod -aG docker phpborg", 10, 'local', true);

        $this->logger->info("Docker installed successfully");
        $this->logger->info("NOTE: phpborg user added to docker group - worker restart may be required");
    }
}
