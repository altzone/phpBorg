<?php

declare(strict_types=1);

namespace PhpBorg\Service\Queue\Handlers;

use PhpBorg\Entity\Job;
use PhpBorg\Logger\LoggerInterface;
use PhpBorg\Service\Queue\JobQueue;

/**
 * Handler for password-based server setup (Method 2 of Add Server Wizard)
 * Uses sshpass to connect with password, install borg, and deploy SSH keys
 */
final class ServerSetupPasswordHandler implements JobHandlerInterface
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    public function handle(Job $job, JobQueue $queue): string
    {
        $payload = $job->payload;

        $hostname = $payload['hostname'] ?? null;
        $port = $payload['port'] ?? 22;
        $username = $payload['username'] ?? 'root';
        $password = $payload['password'] ?? null;
        $useSudo = $payload['use_sudo'] ?? false;

        if (!$hostname || !$password) {
            throw new \Exception('Missing hostname or password in job payload');
        }

        $this->logger->info("Starting password-based server setup for: {$hostname}", 'JOB');
        $queue->updateProgress($job->id, 10, "Connecting to {$hostname}...");

        try {
            // Step 1: Test SSH connection with password
            $queue->updateProgress($job->id, 20, "Testing SSH connection...");
            $this->testPasswordSSH($hostname, $port, $username, $password, $useSudo);
            $this->logger->info("SSH password connection successful for {$hostname}", 'JOB');

            // Step 2: Check if borg is installed
            $queue->updateProgress($job->id, 40, "Checking BorgBackup installation...");
            $borgInstalled = $this->checkBorgInstalled($hostname, $port, $username, $password, $useSudo);

            if (!$borgInstalled) {
                // Step 3: Install borg
                $queue->updateProgress($job->id, 50, "Installing BorgBackup...");
                $this->installBorg($hostname, $port, $username, $password, $useSudo);
                $this->logger->info("BorgBackup installed on {$hostname}", 'JOB');
            } else {
                $this->logger->info("BorgBackup already installed on {$hostname}", 'JOB');
            }

            // Step 4: Deploy phpborg SSH public key
            $queue->updateProgress($job->id, 70, "Deploying SSH key...");
            $this->deploySSHKey($hostname, $port, $username, $password, $useSudo);
            $this->logger->info("SSH key deployed to {$hostname}", 'JOB');

            // Step 5: Test key-based connection
            $queue->updateProgress($job->id, 90, "Verifying SSH key connection...");
            $this->testKeyConnection($hostname, $port, $username);
            $this->logger->info("SSH key connection verified for {$hostname}", 'JOB');

            $queue->updateProgress($job->id, 100, "Server setup completed successfully!");

            return "Server {$hostname} configured successfully with SSH keys and BorgBackup";

        } catch (\Exception $e) {
            $this->logger->error("Password-based setup failed for {$hostname}: " . $e->getMessage(), 'JOB');
            throw $e;
        }
    }

    private function testPasswordSSH(string $hostname, int $port, string $username, string $password, bool $useSudo): void
    {
        $testCommand = $useSudo ? 'sudo echo ok' : 'echo ok';

        $command = sprintf(
            'sshpass -p %s ssh -o StrictHostKeyChecking=no -o ConnectTimeout=10 -p %d %s@%s %s 2>&1',
            escapeshellarg($password),
            $port,
            escapeshellarg($username),
            escapeshellarg($hostname),
            escapeshellarg($testCommand)
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0 || !in_array('ok', $output)) {
            throw new \Exception('SSH password connection failed: ' . implode("\n", $output));
        }
    }

    private function checkBorgInstalled(string $hostname, int $port, string $username, string $password, bool $useSudo): bool
    {
        $checkCommand = $useSudo ? 'sudo borg --version' : 'borg --version';

        $command = sprintf(
            'sshpass -p %s ssh -o StrictHostKeyChecking=no -p %d %s@%s %s 2>&1',
            escapeshellarg($password),
            $port,
            escapeshellarg($username),
            escapeshellarg($hostname),
            escapeshellarg($checkCommand)
        );

        exec($command, $output, $returnCode);

        return $returnCode === 0;
    }

    private function installBorg(string $hostname, int $port, string $username, string $password, bool $useSudo): void
    {
        // Detect OS and install borg
        $sudoPrefix = $useSudo ? 'sudo ' : '';

        $installScript = <<<'BASH'
if [ -f /etc/os-release ]; then
    . /etc/os-release
    OS=$ID
else
    echo "Cannot detect OS"
    exit 1
fi

case "$OS" in
    ubuntu|debian)
        %1$sapt-get update -qq && %1$sapt-get install -y borgbackup
        ;;
    centos|rhel|fedora)
        %1$syum install -y epel-release && %1$syum install -y borgbackup
        ;;
    *)
        echo "Unsupported OS: $OS"
        exit 1
        ;;
esac

borg --version
BASH;

        $installScript = sprintf($installScript, $sudoPrefix);

        $command = sprintf(
            'sshpass -p %s ssh -o StrictHostKeyChecking=no -p %d %s@%s %s 2>&1',
            escapeshellarg($password),
            $port,
            escapeshellarg($username),
            escapeshellarg($hostname),
            escapeshellarg($installScript)
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new \Exception('BorgBackup installation failed: ' . implode("\n", $output));
        }
    }

    private function deploySSHKey(string $hostname, int $port, string $username, string $password, bool $useSudo): void
    {
        // Get phpborg's public key
        $publicKeyPath = '/home/phpborg/.ssh/id_rsa.pub';
        if (!file_exists($publicKeyPath)) {
            throw new \Exception('phpborg SSH public key not found. Please generate it first.');
        }

        $publicKey = trim(file_get_contents($publicKeyPath));

        // Deploy key to root user (where borg will be used)
        $sudoPrefix = $useSudo ? 'sudo ' : '';

        $deployScript = sprintf(
            "%1\$smkdir -p /root/.ssh && %1\$schmod 700 /root/.ssh && echo %2\$s >> /root/.ssh/authorized_keys && %1\$schmod 600 /root/.ssh/authorized_keys && echo 'SSH key deployed'",
            $sudoPrefix,
            escapeshellarg($publicKey)
        );

        $command = sprintf(
            'sshpass -p %s ssh -o StrictHostKeyChecking=no -p %d %s@%s %s 2>&1',
            escapeshellarg($password),
            $port,
            escapeshellarg($username),
            escapeshellarg($hostname),
            escapeshellarg($deployScript)
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new \Exception('SSH key deployment failed: ' . implode("\n", $output));
        }
    }

    private function testKeyConnection(string $hostname, int $port, string $username): void
    {
        // Test connection using phpborg's SSH key (no password)
        $command = sprintf(
            'ssh -o BatchMode=yes -o ConnectTimeout=10 -o StrictHostKeyChecking=no -p %d %s@%s "echo ok" 2>&1',
            $port,
            escapeshellarg($username),
            escapeshellarg($hostname)
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0 || !in_array('ok', $output)) {
            throw new \Exception('SSH key-based connection test failed: ' . implode("\n", $output));
        }
    }
}

