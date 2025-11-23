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

        $this->logger->info('Testing SSH connection', 'SSH_TEST', [
            'hostname' => $hostname,
            'port' => $port,
            'username' => $username,
        ]);

        try {
            // Test SSH connection
            $command = sprintf(
                'ssh -o BatchMode=yes -o ConnectTimeout=10 -o StrictHostKeyChecking=no -p %d %s@%s "echo ok" 2>&1',
                $port,
                escapeshellarg($username),
                escapeshellarg($hostname)
            );

            exec($command, $output, $returnCode);
            $outputStr = implode("\n", $output);

            if ($returnCode === 0 && str_contains($outputStr, 'ok')) {
                // Connection successful, try to get Borg version
                $borgCommand = sprintf(
                    'ssh -o BatchMode=yes -o ConnectTimeout=10 -o StrictHostKeyChecking=no -p %d %s@%s "borg --version 2>&1" 2>&1',
                    $port,
                    escapeshellarg($username),
                    escapeshellarg($hostname)
                );

                exec($borgCommand, $borgOutput, $borgReturnCode);
                $borgVersion = $borgReturnCode === 0 ? trim(implode("\n", $borgOutput)) : null;

                $this->logger->info('SSH connection test successful', 'SSH_TEST', [
                    'hostname' => $hostname,
                    'borg_version' => $borgVersion,
                ]);

                // Store result as JSON in output (will be parsed by frontend)
                $resultJson = json_encode([
                    'success' => true,
                    'borg_version' => $borgVersion,
                ]);

                $queue->updateProgress($job->id, 100, $resultJson);

                return 'completed';
            }

            // Connection failed
            $this->logger->warning('SSH connection test failed', 'SSH_TEST', [
                'hostname' => $hostname,
                'output' => $outputStr,
            ]);

            // Store error result as JSON
            $resultJson = json_encode([
                'success' => false,
                'error' => 'SSH connection failed',
                'output' => $outputStr,
            ]);

            $queue->updateProgress($job->id, 0, $resultJson);

            return 'failed';

        } catch (\Exception $e) {
            return $this->fail($job, $queue, $e->getMessage());
        }
    }

    private function fail(Job $job, JobQueue $queue, string $message): string
    {
        $this->logger->error('Server test connection failed', 'SSH_TEST', [
            'job_id' => $job->id,
            'error' => $message,
        ]);

        // Store error as JSON
        $resultJson = json_encode(['error' => $message]);
        $queue->updateProgress($job->id, 0, $resultJson);

        return 'failed';
    }
}
