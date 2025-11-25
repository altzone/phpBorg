<?php

declare(strict_types=1);

namespace PhpBorg\Service\Queue\Handlers;

use PhpBorg\Config\Configuration;
use PhpBorg\Entity\Job;
use PhpBorg\Logger\LoggerInterface;
use PhpBorg\Logger\UserOperationLogger;
use PhpBorg\Repository\ServerRepository;
use PhpBorg\Service\Queue\JobQueue;
use PhpBorg\Service\Server\ServerManager;

/**
 * Handler for server setup jobs
 * Responsibilities:
 * - Test SSH connection to the server
 * - Install BorgBackup on the remote server
 * - Generate and deploy SSH keys for secure borg serve architecture
 * - Configure authorized_keys with borg serve restrictions
 *
 * Note: Repository creation is handled by BackupCreateHandler when creating backups
 */
final class ServerSetupHandler implements JobHandlerInterface
{
    public function __construct(
        private readonly ServerManager $serverManager,
        private readonly ServerRepository $serverRepo,
        private readonly Configuration $config,
        private readonly LoggerInterface $logger,
        private readonly UserOperationLogger $userLogger
    ) {
    }

    /**
     * Handle server setup job
     * Sets up SSH connection and installs BorgBackup on the remote server
     */
    public function handle(Job $job, JobQueue $queue): string
    {
        $payload = $job->payload;

        $serverId = $payload['server_id'] ?? null;
        $serverName = $payload['server_name'] ?? null;
        $hostname = $payload['hostname'] ?? null;
        $port = $payload['port'] ?? 22;
        $sshUser = $payload['ssh_user'] ?? 'root';

        if (!$serverId) {
            throw new \Exception('Missing server_id in job payload');
        }

        if (!$serverName) {
            throw new \Exception('Missing server_name in job payload');
        }

        if (!$hostname) {
            throw new \Exception('Missing hostname in job payload');
        }

        $this->logger->info("Starting server setup: {$serverName}", 'JOB');
        $queue->updateProgress($job->id, 10, "Starting server setup for: {$serverName}");

        // USER LOG: Server setup started
        $this->userLogger->info('server_setup', "Server setup started: '{$serverName}'", [
            'server_id' => $serverId,
            'server_name' => $serverName,
            'hostname' => $hostname,
            'port' => $port,
            'ssh_user' => $sshUser,
            'job_id' => $job->id
        ]);

        try {
            // Step 1: Test SSH connection (idempotent)
            $queue->updateProgress($job->id, 30, "Testing SSH connection to {$hostname}:{$port}...");
            $this->testSSHConnection($hostname, $port, $sshUser);
            $this->logger->info("SSH connection test passed for {$serverName}", 'JOB');

            // Step 2: Check if BorgBackup is installed (idempotent)
            $queue->updateProgress($job->id, 60, "Checking BorgBackup installation...");
            $borgInstalled = $this->checkBorgInstallation($hostname, $sshUser);

            if (!$borgInstalled) {
                // Step 3: Install BorgBackup if not present
                $queue->updateProgress($job->id, 70, "Installing BorgBackup on remote server...");
                $this->installBorgBackup($hostname, $sshUser);
                $this->logger->info("BorgBackup installed on {$serverName}", 'JOB');
            } else {
                $this->logger->info("BorgBackup already installed on {$serverName}", 'JOB');
            }

            // Step 4: Verify installation
            $queue->updateProgress($job->id, 70, "Verifying BorgBackup installation...");
            $this->verifyBorgInstallation($hostname, $sshUser);

            // Step 5: Generate SSH keys for this server (idempotent)
            $queue->updateProgress($job->id, 75, "Generating SSH keys for secure backup...");
            $keyPair = $this->generateSSHKeys($serverName, $serverId);
            $this->logger->info("SSH keys generated for {$serverName}", 'JOB');

            // Step 6: Deploy private key to remote server
            $queue->updateProgress($job->id, 80, "Deploying SSH keys to remote server...");
            $privateKeyPath = $this->deployPrivateKeyToRemote($hostname, $sshUser, $keyPair['private'], $serverName);
            $this->logger->info("Private key deployed to {$hostname}:{$privateKeyPath}", 'JOB');

            // Step 7: Configure authorized_keys on backup server with borg serve restriction
            $queue->updateProgress($job->id, 90, "Configuring secure borg serve access...");
            $this->configureAuthorizedKeys($serverName, $keyPair['public']);
            $this->logger->info("Authorized keys configured with borg serve restriction", 'JOB');

            // Step 8: Update server record in database
            $queue->updateProgress($job->id, 95, "Updating server configuration...");
            $this->serverRepo->updateSSHKeys(
                $serverId,
                $keyPair['public'],
                $privateKeyPath,
                true,
                'phpborg'
            );
            $this->logger->info("Server record updated with SSH configuration", 'JOB');

            $queue->updateProgress($job->id, 100, "Server setup completed successfully.");
            $this->logger->info("Server setup completed for {$serverName}", 'JOB');

            // USER LOG: Server setup completed successfully
            $this->userLogger->info('server_setup', "Server setup completed successfully: '{$serverName}'", [
                'server_id' => $serverId,
                'server_name' => $serverName,
                'hostname' => $hostname,
                'borg_installed' => true,
                'ssh_configured' => true,
                'job_id' => $job->id
            ]);

            return "Server '{$serverName}' configured successfully. SSH keys deployed and borg serve access secured.";

        } catch (\Exception $e) {
            $this->logger->error("Server setup failed: {$e->getMessage()}", 'JOB');

            // USER LOG: Server setup failed
            $this->userLogger->error('server_setup', "Server setup failed: {$e->getMessage()}", [
                'server_id' => $serverId,
                'server_name' => $serverName,
                'hostname' => $hostname,
                'error' => $e->getMessage(),
                'job_id' => $job->id
            ]);

            throw new \Exception("Server setup failed: {$e->getMessage()}");
        }
    }

    /**
     * Test SSH connection to remote server
     */
    private function testSSHConnection(string $hostname, int $port, string $user): void
    {
        $command = sprintf(
            'ssh -o BatchMode=yes -o ConnectTimeout=10 -o StrictHostKeyChecking=no -p %d %s@%s "echo SSH_OK" 2>&1',
            $port,
            escapeshellarg($user),
            escapeshellarg($hostname)
        );

        $this->logger->info("Testing SSH: {$command}", 'JOB');

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        $outputStr = implode("\n", $output);
        $this->logger->info("SSH test output: {$outputStr}", 'JOB');

        if ($returnCode !== 0 || !str_contains($outputStr, 'SSH_OK')) {
            throw new \Exception("SSH connection failed to {$user}@{$hostname}:{$port} - Output: {$outputStr}");
        }

        $this->logger->info("SSH connection successful to {$hostname}", 'JOB');
    }

    /**
     * Check if BorgBackup is installed on remote server
     */
    private function checkBorgInstallation(string $hostname, string $sshUser): bool
    {
        $command = sprintf(
            'ssh -o StrictHostKeyChecking=no %s@%s "which borg" 2>&1',
            escapeshellarg($sshUser),
            escapeshellarg($hostname)
        );

        $this->logger->info("Checking Borg installation: {$command}", 'JOB');

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        $outputStr = implode("\n", $output);
        $this->logger->info("Borg check output: {$outputStr}", 'JOB');

        // Return code 0 means borg is found
        return $returnCode === 0 && !empty($outputStr);
    }

    /**
     * Install BorgBackup on remote server
     */
    private function installBorgBackup(string $hostname, string $sshUser): void
    {
        // Try to detect OS and install accordingly
        // First, try apt-get (Debian/Ubuntu)
        // Note: No sudo needed as we connect as root
        $command = sprintf(
            'ssh -o StrictHostKeyChecking=no %s@%s "apt-get update && apt-get install -y borgbackup" 2>&1',
            escapeshellarg($sshUser),
            escapeshellarg($hostname)
        );

        $this->logger->info("Installing Borg: {$command}", 'JOB');

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        $outputStr = implode("\n", $output);
        $this->logger->info("Borg installation output: {$outputStr}", 'JOB');

        if ($returnCode !== 0) {
            // Try yum (CentOS/RHEL) as fallback
            $command = sprintf(
                'ssh -o StrictHostKeyChecking=no %s@%s "yum install -y borgbackup" 2>&1',
                escapeshellarg($sshUser),
                escapeshellarg($hostname)
            );

            $this->logger->info("Trying yum installation: {$command}", 'JOB');

            $output = [];
            exec($command, $output, $returnCode);

            $outputStr = implode("\n", $output);
            $this->logger->info("Yum installation output: {$outputStr}", 'JOB');

            if ($returnCode !== 0) {
                throw new \Exception("Failed to install BorgBackup. Output: {$outputStr}");
            }
        }

        $this->logger->info("BorgBackup installed successfully", 'JOB');
    }

    /**
     * Verify BorgBackup installation
     */
    private function verifyBorgInstallation(string $hostname, string $sshUser): void
    {
        $command = sprintf(
            'ssh -o StrictHostKeyChecking=no %s@%s "borg --version" 2>&1',
            escapeshellarg($sshUser),
            escapeshellarg($hostname)
        );

        $this->logger->info("Verifying Borg: {$command}", 'JOB');

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        $outputStr = implode("\n", $output);
        $this->logger->info("Borg version output: {$outputStr}", 'JOB');

        if ($returnCode !== 0 || !str_contains($outputStr, 'borg')) {
            throw new \Exception("BorgBackup verification failed. Output: {$outputStr}");
        }

        $this->logger->info("BorgBackup verified: {$outputStr}", 'JOB');
    }

    /**
     * Generate SSH key pair for this server
     * Keys are stored in /var/lib/phpborg/.ssh/keys/{serverName}/
     *
     * @return array{public: string, private: string, path: string}
     */
    private function generateSSHKeys(string $serverName, int $serverId): array
    {
        // Keys directory for phpborg user
        $keysDir = '/var/lib/phpborg/.ssh/keys/' . $serverName;
        $privateKeyPath = $keysDir . '/id_ed25519';
        $publicKeyPath = $privateKeyPath . '.pub';

        // Check if keys already exist (idempotent)
        if (file_exists($privateKeyPath) && file_exists($publicKeyPath)) {
            $this->logger->info("SSH keys already exist for {$serverName}, reusing", 'JOB');
            return [
                'public' => file_get_contents($publicKeyPath),
                'private' => file_get_contents($privateKeyPath),
                'path' => $privateKeyPath,
            ];
        }

        // Create keys directory
        if (!is_dir($keysDir)) {
            if (!mkdir($keysDir, 0700, true)) {
                throw new \Exception("Failed to create keys directory: {$keysDir}");
            }
        }

        // Generate SSH key pair (Ed25519 for security and performance)
        $command = sprintf(
            'ssh-keygen -t ed25519 -f %s -N "" -C "phpborg-server-%s-%d" 2>&1',
            escapeshellarg($privateKeyPath),
            escapeshellarg($serverName),
            $serverId
        );

        $this->logger->info("Generating SSH keys: {$command}", 'JOB');

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        $outputStr = implode("\n", $output);

        if ($returnCode !== 0) {
            $this->logger->error("SSH key generation failed: {$outputStr}", 'JOB');
            throw new \Exception("Failed to generate SSH keys: {$outputStr}");
        }

        // Set proper permissions
        chmod($privateKeyPath, 0600);
        chmod($publicKeyPath, 0644);

        $publicKey = file_get_contents($publicKeyPath);
        $privateKey = file_get_contents($privateKeyPath);

        $this->logger->info("SSH keys generated successfully at {$privateKeyPath}", 'JOB');

        return [
            'public' => $publicKey,
            'private' => $privateKey,
            'path' => $privateKeyPath,
        ];
    }

    /**
     * Deploy private key to remote server
     * Copies the private key to the remote server so it can connect back to backup server
     */
    private function deployPrivateKeyToRemote(
        string $hostname,
        string $sshUser,
        string $privateKey,
        string $serverName
    ): string {
        $remoteKeyPath = '/root/.ssh/phpborg_backup';

        // Create .ssh directory on remote server if needed
        $command = sprintf(
            'ssh -o StrictHostKeyChecking=no %s@%s "mkdir -p /root/.ssh && chmod 700 /root/.ssh" 2>&1',
            escapeshellarg($sshUser),
            escapeshellarg($hostname)
        );

        $this->logger->info("Creating .ssh directory on remote: {$command}", 'JOB');

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            $outputStr = implode("\n", $output);
            throw new \Exception("Failed to create .ssh directory on remote: {$outputStr}");
        }

        // Copy private key to remote server using heredoc
        $escapedKey = str_replace("'", "'\\''", $privateKey);
        $command = sprintf(
            "ssh -o StrictHostKeyChecking=no %s@%s 'cat > %s && chmod 600 %s' <<'EOF'\n%s\nEOF",
            escapeshellarg($sshUser),
            escapeshellarg($hostname),
            escapeshellarg($remoteKeyPath),
            escapeshellarg($remoteKeyPath),
            $privateKey
        );

        $this->logger->info("Deploying private key to {$hostname}:{$remoteKeyPath}", 'JOB');

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            $outputStr = implode("\n", $output);
            throw new \Exception("Failed to deploy private key to remote: {$outputStr}");
        }

        $this->logger->info("Private key deployed successfully to remote server", 'JOB');

        return $remoteKeyPath;
    }

    /**
     * Configure authorized_keys on backup server with borg serve restriction
     * This ensures the remote server can ONLY run "borg serve" and nothing else
     */
    private function configureAuthorizedKeys(string $serverName, string $publicKey): void
    {
        $authorizedKeysPath = '/home/phpborg/.ssh/authorized_keys';
        $repoPath = $this->config->borgBackupPath . '/' . $serverName;

        // Create .ssh directory for phpborg user if needed
        $sshDir = '/home/phpborg/.ssh';
        if (!is_dir($sshDir)) {
            if (!mkdir($sshDir, 0700, true)) {
                throw new \Exception("Failed to create {$sshDir}");
            }
        }

        // Clean public key (remove comments and newlines)
        $publicKey = trim($publicKey);

        // Build restricted authorized_keys entry
        // This forces the connection to only run "borg serve" for this specific repository
        $restrictions = [
            'command="borg serve --restrict-to-path ' . $repoPath . '"',
            'no-port-forwarding',
            'no-X11-forwarding',
            'no-agent-forwarding',
            'no-pty',
        ];

        $authorizedKeyEntry = implode(',', $restrictions) . ' ' . $publicKey . ' phpborg-' . $serverName . "\n";

        // Check if entry already exists (idempotent)
        if (file_exists($authorizedKeysPath)) {
            $currentContent = file_get_contents($authorizedKeysPath);
            if (str_contains($currentContent, 'phpborg-' . $serverName)) {
                $this->logger->info("Authorized key already configured for {$serverName}, skipping", 'JOB');
                return;
            }
        }

        // Append to authorized_keys
        if (file_put_contents($authorizedKeysPath, $authorizedKeyEntry, FILE_APPEND | LOCK_EX) === false) {
            throw new \Exception("Failed to write to {$authorizedKeysPath}");
        }

        // Set proper permissions
        chmod($authorizedKeysPath, 0600);

        $this->logger->info("Authorized key configured with borg serve restriction for {$serverName}", 'JOB');
    }
}
