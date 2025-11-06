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
     */
    private function testSSHConnection(string $hostname, int $port, string $user): void
    {
        $command = sprintf(
            'ssh -o BatchMode=yes -o ConnectTimeout=10 -p %d %s@%s "echo SSH_OK" 2>&1',
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
    private function checkBorgInstallation(string $hostname): bool
    {
        $command = sprintf(
            'ssh %s "which borg" 2>&1',
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
    private function installBorgBackup(string $hostname): void
    {
        // Try to detect OS and install accordingly
        // First, try apt-get (Debian/Ubuntu)
        $command = sprintf(
            'ssh %s "sudo apt-get update && sudo apt-get install -y borgbackup" 2>&1',
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
                'ssh %s "sudo yum install -y borgbackup" 2>&1',
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
    private function verifyBorgInstallation(string $hostname): void
    {
        $command = sprintf(
            'ssh %s "borg --version" 2>&1',
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
}
