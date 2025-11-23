<?php

declare(strict_types=1);

namespace PhpBorg\Service\Queue\Handlers;

use PhpBorg\Entity\Job;
use PhpBorg\Logger\LoggerInterface;
use PhpBorg\Service\Queue\JobQueue;

/**
 * Handler for testing SSH connection to a server
 * Tests if phpborg can connect using SSH keys
 */
class ServerTestConnectionHandler implements JobHandlerInterface
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

        if (!$hostname) {
            return $this->fail($job, $queue, 'Missing hostname parameter');
        }

        $this->logger->info('Testing SSH connection', [
            'hostname' => $hostname,
            'port' => $port,
            'username' => $username,
        ]);

        try {
            // Update job progress
            $queue->updateProgress($job->id, 10, 'Testing SSH connection...');

            // Test SSH connection
            $command = sprintf(
                'ssh -o BatchMode=yes -o ConnectTimeout=10 -o StrictHostKeyChecking=no -p %d %s@%s "echo ok" 2>&1',
                $port,
                escapeshellarg($username),
                escapeshellarg($hostname)
            );

            $queue->updateProgress($job->id, 50, 'Connecting to server...');

            exec($command, $output, $returnCode);
            $outputStr = implode("\n", $output);

            if ($returnCode === 0 && str_contains($outputStr, 'ok')) {
                // Connection successful, try to get Borg version
                $queue->updateProgress($job->id, 75, 'Checking Borg installation...');

                $borgCommand = sprintf(
                    'ssh -o BatchMode=yes -o ConnectTimeout=10 -o StrictHostKeyChecking=no -p %d %s@%s "borg --version 2>&1" 2>&1',
                    $port,
                    escapeshellarg($username),
                    escapeshellarg($hostname)
                );

                exec($borgCommand, $borgOutput, $borgReturnCode);
                $borgVersion = $borgReturnCode === 0 ? trim(implode("\n", $borgOutput)) : null;

                $queue->updateProgress($job->id, 100, 'Connection successful');

                $this->logger->info('SSH connection test successful', [
                    'hostname' => $hostname,
                    'borg_version' => $borgVersion,
                ]);

                $job->result_data = [
                    'success' => true,
                    'borg_version' => $borgVersion,
                ];

                return 'completed';
            }

            // Connection failed
            $this->logger->warning('SSH connection test failed', [
                'hostname' => $hostname,
                'output' => $outputStr,
            ]);

            $job->result_data = [
                'success' => false,
                'error' => 'SSH connection failed',
                'output' => $outputStr,
            ];

            return 'failed';

        } catch (\Exception $e) {
            return $this->fail($job, $queue, $e->getMessage());
        }
    }

    private function fail(Job $job, JobQueue $queue, string $message): string
    {
        $this->logger->error('Server test connection failed', [
            'job_id' => $job->id,
            'error' => $message,
        ]);

        $queue->updateProgress($job->id, 0, "Failed: {$message}");
        $job->result_data = ['error' => $message];

        return 'failed';
    }
}
