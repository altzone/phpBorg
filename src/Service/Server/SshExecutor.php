<?php

declare(strict_types=1);

namespace PhpBorg\Service\Server;

use PhpBorg\Entity\Server;
use PhpBorg\Exception\SshException;
use PhpBorg\Logger\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Execute SSH commands securely
 */
final class SshExecutor
{
    private const SSH_OPTIONS = [
        'BatchMode=yes',
        'ConnectTimeout=5',
        'StrictHostKeyChecking=no',
        'ServerAliveInterval=60',
        'ServerAliveCountMax=3',
    ];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Execute command on remote server via SSH
     *
     * @param bool $tty Allocate pseudo-terminal (use false for non-interactive commands like borg backup)
     * @return array{stdout: string, stderr: string, exitCode: int}
     * @throws SshException
     */
    public function execute(Server $server, string $command, int $timeout = 300, bool $tty = false, ?callable $progressCallback = null): array
    {
        $sshCommand = $this->buildSshCommand($server, $command, $tty);

        $process = new Process($sshCommand, null, null, null, $timeout);

        $this->logger->debug("Executing SSH command on {$server->name}", 'SSH');

        // If progress callback provided, capture output in real-time
        if ($progressCallback !== null) {
            $process->run(function ($type, $buffer) use ($progressCallback) {
                // Borg sends progress on stderr (with --progress or --log-json)
                if ($type === Process::ERR) {
                    $progressCallback($buffer);
                }
            });
        } else {
            // Standard execution without real-time output
            $process->run();
        }

        if (!$process->isSuccessful()) {
            $this->logger->error(
                "SSH command failed on {$server->name}: " . $process->getErrorOutput(),
                'SSH'
            );
        }

        return [
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
            'exitCode' => $process->getExitCode() ?? 1,
        ];
    }

    /**
     * Test SSH connectivity
     *
     * @throws SshException
     */
    public function testConnection(Server $server): bool
    {
        try {
            $result = $this->execute($server, 'echo "Connection OK"', 10);
            return $result['exitCode'] === 0;
        } catch (SshException $e) {
            $this->logger->warning("SSH connection test failed for {$server->name}: {$e->getMessage()}", 'SSH');
            return false;
        }
    }

    /**
     * Test reverse SSH connectivity (from remote to backup server)
     * Tests if remote server can connect back using deployed SSH key
     *
     * @throws SshException
     */
    public function testReverseConnection(Server $server, string $backupServerIp): bool
    {
        // Use deployed SSH key and connect as phpborg user (with borg serve restriction)
        // Note: Due to borg serve restriction, we can only run borg commands
        // Note: Using StrictHostKeyChecking=no for compatibility with OpenSSH < 7.6
        $sshKeyPath = '/root/.ssh/phpborg_backup';
        $command = sprintf(
            'ssh -i %s -q -o BatchMode=yes -o ConnectTimeout=3 -o StrictHostKeyChecking=no phpborg@%s "borg --version"',
            escapeshellarg($sshKeyPath),
            $backupServerIp
        );

        $result = $this->execute($server, $command, 15);

        if ($result['exitCode'] !== 0) {
            $this->logger->error(
                "Reverse SSH connection failed from {$server->name} to phpborg@{$backupServerIp}",
                'SSH',
                ['error' => $result['stderr']]
            );
            return false;
        }

        $this->logger->info("Reverse SSH connection OK for {$server->name} (using borg serve)", 'SSH');
        return true;
    }

    /**
     * Get remote file content
     *
     * @throws SshException
     */
    public function getFileContent(Server $server, string $remotePath): string
    {
        $result = $this->execute($server, "cat {$remotePath}", 60);

        if ($result['exitCode'] !== 0) {
            throw new SshException("Failed to read file {$remotePath}: {$result['stderr']}");
        }

        return $result['stdout'];
    }

    /**
     * Check if file exists on remote server
     */
    public function fileExists(Server $server, string $remotePath): bool
    {
        $result = $this->execute($server, "test -f {$remotePath} && echo 'exists'", 10);
        return $result['exitCode'] === 0 && trim($result['stdout']) === 'exists';
    }

    /**
     * Create directory on remote server
     *
     * @throws SshException
     */
    public function createDirectory(Server $server, string $remotePath, int $mode = 0755): void
    {
        $modeOctal = decoct($mode);
        $result = $this->execute($server, "mkdir -p -m {$modeOctal} {$remotePath}", 30);

        if ($result['exitCode'] !== 0) {
            throw new SshException("Failed to create directory {$remotePath}: {$result['stderr']}");
        }
    }

    /**
     * Build SSH command array
     *
     * @param bool $tty Allocate pseudo-terminal (use -tt flag)
     * @return array<int, string>
     */
    private function buildSshCommand(Server $server, string $command, bool $tty = false): array
    {
        $sshCommand = [
            'ssh',
            '-p', (string)$server->port,
        ];

        // Only allocate TTY for interactive commands
        if ($tty) {
            $sshCommand[] = '-tt';
        } else {
            $sshCommand[] = '-T'; // Disable pseudo-terminal allocation
        }

        foreach (self::SSH_OPTIONS as $option) {
            $sshCommand[] = '-o';
            $sshCommand[] = $option;
        }

        // Connect as root to allow full system backup
        $sshCommand[] = 'root@' . $server->host;
        $sshCommand[] = $command;

        return $sshCommand;
    }
}
