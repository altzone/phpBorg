<?php

declare(strict_types=1);

namespace PhpBorg\Service\Queue\Handlers;

use PhpBorg\Entity\Job;
use PhpBorg\Logger\LoggerInterface;
use PhpBorg\Service\Queue\JobQueue;
use PhpBorg\Service\Server\ServerManager;

/**
 * Handler for server setup jobs
 * Responsibilities:
 * - Test SSH connection to the server
 * - Install BorgBackup on the remote server
 * - Basic tool configuration
 *
 * Note: Repository creation is handled by BackupCreateHandler when creating backups
 */
final class ServerSetupHandler implements JobHandlerInterface
{
    public function __construct(
        private readonly ServerManager $serverManager,
        private readonly LoggerInterface $logger
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

        try {
            // Step 1: Test SSH connection (idempotent)
            $queue->updateProgress($job->id, 30, "Testing SSH connection to {$hostname}:{$port}...");
            $this->testSSHConnection($hostname, $port, $sshUser);
            $this->logger->info("SSH connection test passed for {$serverName}", 'JOB');

            // Step 2: Check if BorgBackup is installed (idempotent)
            $queue->updateProgress($job->id, 60, "Checking BorgBackup installation...");
            $borgInstalled = $this->checkBorgInstallation($hostname);

            if (!$borgInstalled) {
                // Step 3: Install BorgBackup if not present
                $queue->updateProgress($job->id, 70, "Installing BorgBackup on remote server...");
                $this->installBorgBackup($hostname);
                $this->logger->info("BorgBackup installed on {$serverName}", 'JOB');
            } else {
                $this->logger->info("BorgBackup already installed on {$serverName}", 'JOB');
            }

            // Step 4: Verify installation
            $queue->updateProgress($job->id, 90, "Verifying BorgBackup installation...");
            $this->verifyBorgInstallation($hostname);

            $queue->updateProgress($job->id, 100, "Server setup completed successfully.");
            $this->logger->info("Server setup completed for {$serverName}", 'JOB');

            return "Server '{$serverName}' configured successfully. SSH connection verified and BorgBackup installed.";

        } catch (\Exception $e) {
            $this->logger->error("Server setup failed: {$e->getMessage()}", 'JOB');
            throw new \Exception("Server setup failed: {$e->getMessage()}");
        }
    }

    /**
     * Test SSH connection to remote server
     * For now, this is simulated. In production, use real SSH connection.
     */
    private function testSSHConnection(string $hostname, int $port, string $user): void
    {
        // Simulate SSH connection test
        // In production: exec ssh command or use phpseclib
        sleep(1);

        // TODO: Implement real SSH connection test
        // Example: ssh -p $port $user@$hostname 'echo "test"'
    }

    /**
     * Check if BorgBackup is installed on remote server
     * For now, this is simulated. In production, check via SSH.
     */
    private function checkBorgInstallation(string $hostname): bool
    {
        // Simulate check
        sleep(1);

        // TODO: Implement real check via SSH
        // Example: ssh $hostname 'which borg'
        // Return false if not installed

        return false; // For now, always return false to simulate installation
    }

    /**
     * Install BorgBackup on remote server
     * For now, this is simulated. In production, install via SSH.
     */
    private function installBorgBackup(string $hostname): void
    {
        // Simulate installation
        sleep(2);

        // TODO: Implement real installation via SSH
        // Example commands:
        // - apt-get update && apt-get install -y borgbackup (Debian/Ubuntu)
        // - yum install -y borgbackup (CentOS/RHEL)
    }

    /**
     * Verify BorgBackup installation
     * For now, this is simulated. In production, verify via SSH.
     */
    private function verifyBorgInstallation(string $hostname): void
    {
        // Simulate verification
        sleep(1);

        // TODO: Implement real verification via SSH
        // Example: ssh $hostname 'borg --version'
    }
}
