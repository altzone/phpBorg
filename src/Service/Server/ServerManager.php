<?php

declare(strict_types=1);

namespace PhpBorg\Service\Server;

use PhpBorg\Config\Configuration;
use PhpBorg\Entity\Server;
use PhpBorg\Exception\PhpBorgException;
use PhpBorg\Logger\LoggerInterface;
use PhpBorg\Repository\BorgRepositoryRepository;
use PhpBorg\Repository\ServerRepository;
use PhpBorg\Service\Backup\BorgExecutor;
use PhpBorg\Service\Repository\EncryptionService;

/**
 * Server management service
 */
final class ServerManager
{
    public function __construct(
        private readonly Configuration $config,
        private readonly ServerRepository $serverRepo,
        private readonly BorgRepositoryRepository $repoRepo,
        private readonly SshExecutor $sshExecutor,
        private readonly BorgExecutor $borgExecutor,
        private readonly EncryptionService $encryption,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Get all servers
     *
     * @return Server[]
     */
    public function getAllServers(): array
    {
        return $this->serverRepo->findAll();
    }

    /**
     * Get server by ID
     */
    public function getServerById(int $id): ?Server
    {
        return $this->serverRepo->findById($id);
    }

    /**
     * Create server (simple version for API)
     */
    public function createServer(
        string $name,
        string $hostname,
        int $port,
        string $username,
        ?string $description = null,
        string $backupType = 'internal'
    ): int {
        $this->logger->info("Creating server: {$name} (type: {$backupType})", 'SYSTEM');

        // Check if server already exists
        if ($this->serverRepo->findByName($name) !== null) {
            throw new PhpBorgException("Server {$name} already exists");
        }

        // Validate backupType
        if (!in_array($backupType, ['internal', 'external'])) {
            throw new PhpBorgException("Invalid backup type: {$backupType}");
        }

        return $this->serverRepo->create(
            name: $name,
            host: $hostname,
            port: $port,
            backupType: $backupType,
            sshPublicKey: '',
            active: true
        );
    }

    /**
     * Update server
     */
    public function updateServer(
        int $serverId,
        ?string $name = null,
        ?string $hostname = null,
        ?int $port = null,
        ?string $username = null,
        ?string $description = null,
        ?bool $active = null
    ): void {
        $this->logger->info("Updating server ID: {$serverId}", 'SYSTEM');

        $server = $this->serverRepo->findById($serverId);
        if (!$server) {
            throw new PhpBorgException("Server not found");
        }

        // Update only provided fields
        if ($name !== null) {
            $server = new Server(
                id: $server->id,
                name: $name,
                host: $server->host,
                port: $server->port,
                backupType: $server->backupType,
                sshPublicKey: $server->sshPublicKey,
                active: $server->active
            );
        }
        if ($hostname !== null) {
            $server = new Server(
                id: $server->id,
                name: $server->name,
                host: $hostname,
                port: $server->port,
                backupType: $server->backupType,
                sshPublicKey: $server->sshPublicKey,
                active: $server->active
            );
        }
        if ($port !== null) {
            $server = new Server(
                id: $server->id,
                name: $server->name,
                host: $server->host,
                port: $port,
                backupType: $server->backupType,
                sshPublicKey: $server->sshPublicKey,
                active: $server->active
            );
        }
        if ($active !== null) {
            $server = new Server(
                id: $server->id,
                name: $server->name,
                host: $server->host,
                port: $server->port,
                backupType: $server->backupType,
                sshPublicKey: $server->sshPublicKey,
                active: $active
            );
        }

        $this->serverRepo->update($server);
    }

    /**
     * Delete server
     */
    public function deleteServer(int $serverId): void
    {
        $this->logger->info("Deleting server ID: {$serverId}", 'SYSTEM');

        $server = $this->serverRepo->findById($serverId);
        if (!$server) {
            throw new PhpBorgException("Server not found");
        }

        $this->serverRepo->delete($serverId);
    }

    /**
     * Archive server (set active=0)
     */
    public function archiveServer(int $serverId): void
    {
        $this->logger->info("Archiving server ID: {$serverId}", 'SYSTEM');

        $server = $this->serverRepo->findById($serverId);
        if (!$server) {
            throw new PhpBorgException("Server not found");
        }

        $this->serverRepo->setActive($serverId, false);
    }

    /**
     * Get repositories for a server
     */
    public function getRepositoriesForServer(int $serverId): array
    {
        return $this->repoRepo->findByServerId($serverId);
    }

    /**
     * Add new server to backup system (full setup)
     *
     * @throws PhpBorgException
     */
    public function addServer(
        string $name,
        int $sshPort = 22,
        int $retention = 8,
        string $backupType = 'internal'
    ): int {
        $this->logger->info("Adding new server: {$name}", 'SYSTEM');

        // Check if server already exists
        if ($this->serverRepo->findByName($name) !== null) {
            throw new PhpBorgException("Server {$name} already exists");
        }

        // Connect to server and setup
        $server = new Server(
            id: 0,
            name: $name,
            host: $name,
            port: $sshPort,
            backupType: $backupType,
            sshPublicKey: '',
            active: true
        );

        // Test SSH connection
        if (!$this->sshExecutor->testConnection($server)) {
            throw new PhpBorgException("Cannot connect to server {$name} via SSH");
        }

        $this->logger->info("SSH connection OK", $name);

        // Generate or retrieve SSH key
        $sshKey = $this->setupSshKey($server);

        // Install Borg Backup
        $this->installBorgBackup($server);

        // Create local user
        $this->createLocalUser($name);

        // Create repository directory
        $repoPath = $this->config->borgBackupPath . '/' . $name;
        if (!is_dir($repoPath)) {
            mkdir($repoPath, 0755, true);
        }

        // Setup SSH keys locally
        $this->setupLocalSshKeys($name, $sshKey);

        // Initialize Borg repository
        $passphrase = $this->encryption->generatePassphrase();
        $backupDir = $repoPath . '/backup';

        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $this->borgExecutor->initRepository($backupDir, $passphrase, 'repokey');

        // Create restore directory
        $restoreDir = $repoPath . '/restore';
        if (!is_dir($restoreDir)) {
            mkdir($restoreDir, 0755, true);
        }

        // Set permissions
        $this->setPermissions($repoPath, $name);

        // Save server to database
        $serverId = $this->serverRepo->create(
            name: $name,
            host: $name,
            port: $sshPort,
            backupType: $backupType,
            sshPublicKey: $sshKey,
            active: true
        );

        // Get repository ID
        $repoConfig = parse_ini_file($backupDir . '/config');
        $repoId = $repoConfig['id'] ?? throw new PhpBorgException('Failed to read repository ID');

        // Save repository to database
        $this->repoRepo->create(
            serverId: $serverId,
            repoId: $repoId,
            type: 'backup',
            retention: $retention,
            encryption: 'repokey',
            passphrase: $passphrase,
            repoPath: $backupDir,
            compression: 'lz4',
            rateLimit: 0,
            backupPath: '/',
            exclude: '/proc,/dev,/sys,/tmp,/run,/var/run,/lost+found,/var/cache/apt/archives,/var/lib/mysql,/var/lib/lxcfs'
        );

        $this->logger->info("Server {$name} added successfully", 'SYSTEM');

        return $serverId;
    }

    /**
     * Setup SSH key on remote server
     */
    private function setupSshKey(Server $server): string
    {
        $this->logger->info("Setting up SSH key", $server->name);

        // Check if key exists
        if ($this->sshExecutor->fileExists($server, '/root/.ssh/id_rsa.pub')) {
            $this->logger->info("SSH key already exists, retrieving it", $server->name);
            return trim($this->sshExecutor->getFileContent($server, '/root/.ssh/id_rsa.pub'));
        }

        // Generate key
        $command = "ssh-keygen -t rsa -b 4096 -f /root/.ssh/id_rsa -N '' -C 'phpborg@{$server->name}'";
        $result = $this->sshExecutor->execute($server, $command, 60);

        if ($result['exitCode'] !== 0) {
            throw new PhpBorgException("Failed to generate SSH key: {$result['stderr']}");
        }

        $this->logger->info("SSH key generated", $server->name);

        return trim($this->sshExecutor->getFileContent($server, '/root/.ssh/id_rsa.pub'));
    }

    /**
     * Install Borg Backup on remote server
     */
    private function installBorgBackup(Server $server): void
    {
        $this->logger->info("Installing Borg Backup", $server->name);

        // Check if already installed
        $checkCommand = "which borg && echo 'installed'";
        $result = $this->sshExecutor->execute($server, $checkCommand, 10);

        if (str_contains($result['stdout'], 'installed')) {
            $this->logger->info("Borg Backup already installed", $server->name);
            return;
        }

        // Install via package manager or download binary
        $installCommand = "if command -v apt-get &> /dev/null; then apt-get update && apt-get install -y borgbackup; "
            . "elif command -v yum &> /dev/null; then yum install -y borgbackup; "
            . "elif command -v dnf &> /dev/null; then dnf install -y borgbackup; "
            . "else wget -O /usr/bin/borg https://github.com/borgbackup/borg/releases/download/1.2.7/borg-linux64 && chmod +x /usr/bin/borg; fi";

        $result = $this->sshExecutor->execute($server, $installCommand, 300);

        if ($result['exitCode'] !== 0) {
            throw new PhpBorgException("Failed to install Borg Backup: {$result['stderr']}");
        }

        $this->logger->info("Borg Backup installed", $server->name);
    }

    /**
     * Create local user for backup
     */
    private function createLocalUser(string $username): void
    {
        $this->logger->info("Creating local user: {$username}", 'SYSTEM');

        if (posix_getpwnam($username) !== false) {
            $this->logger->info("User already exists", 'SYSTEM');
            return;
        }

        $homeDir = $this->config->borgBackupPath . '/' . $username;
        $command = "useradd -d {$homeDir} -m {$username}";

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new PhpBorgException("Failed to create user {$username}");
        }

        $this->logger->info("User created", 'SYSTEM');
    }

    /**
     * Setup local SSH keys
     */
    private function setupLocalSshKeys(string $username, string $publicKey): void
    {
        $this->logger->info("Setting up local SSH keys", 'SYSTEM');

        $sshDir = $this->config->borgBackupPath . '/' . $username . '/.ssh';
        $authorizedKeysFile = $sshDir . '/authorized_keys';

        if (!is_dir($sshDir)) {
            mkdir($sshDir, 0700, true);
        }

        file_put_contents($authorizedKeysFile, $publicKey . PHP_EOL);
        chmod($authorizedKeysFile, 0600);

        $this->logger->info("SSH keys configured", 'SYSTEM');
    }

    /**
     * Set directory permissions
     */
    private function setPermissions(string $path, string $owner): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $item) {
            @chmod($item->getPathname(), 0700);
            @chgrp($item->getPathname(), $owner);
            @chown($item->getPathname(), $owner);
        }
    }
}
