<?php

declare(strict_types=1);

namespace PhpBorg\Service\Queue\Handlers;

use PhpBorg\Entity\Job;
use PhpBorg\Entity\Server;
use PhpBorg\Logger\LoggerInterface;
use PhpBorg\Repository\ServerRepository;
use PhpBorg\Repository\ServerStatsRepository;
use PhpBorg\Service\Queue\JobQueue;
use PhpBorg\Service\Server\ServerStatsCollector;

/**
 * Handler for server stats collection jobs
 * Collects system metrics from remote servers via SSH or agent
 *
 * For agent-mode servers, this creates a task for the agent to execute.
 * For SSH-mode servers, this connects via SSH directly.
 */
final class ServerStatsCollectHandler implements JobHandlerInterface
{
    public function __construct(
        private readonly ServerRepository $serverRepository,
        private readonly ServerStatsRepository $statsRepository,
        private readonly ServerStatsCollector $statsCollector,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Handle server stats collection job
     */
    public function handle(Job $job, JobQueue $queue): string
    {
        $payload = $job->payload;
        $serverId = $payload['server_id'] ?? null;

        if (!$serverId) {
            throw new \Exception('Missing server_id in job payload');
        }

        $server = $this->serverRepository->findById($serverId);
        if (!$server) {
            throw new \Exception("Server not found: {$serverId}");
        }

        if (!$server->active) {
            throw new \Exception("Server is inactive: {$server->name}");
        }

        $this->logger->info("Starting stats collection for server: {$server->name} (mode: {$server->connectionMode})", 'STATS');

        // Route based on connection mode
        if ($server->connectionMode === 'agent') {
            return $this->handleViaAgent($server, $job, $queue);
        }

        // Default: SSH mode
        $queue->updateProgress($job->id, 10, "Connecting to server {$server->name} via SSH...");

        try {
            // Collect all system metrics
            $queue->updateProgress($job->id, 30, "Collecting system information...");
            $stats = $this->statsCollector->collectStats($server);

            $queue->updateProgress($job->id, 80, "Saving collected metrics...");

            // Save stats to database
            $this->statsRepository->saveStats($serverId, $stats);

            $queue->updateProgress($job->id, 100, "Stats collection completed");

            $this->logger->info("Stats collection completed for server {$server->name}", 'STATS', [
                'server_id' => $serverId,
                'metrics_collected' => count($stats)
            ]);

            // Return summary
            $summary = [
                'server_id' => $serverId,
                'server_name' => $server->name,
                'metrics_collected' => count($stats),
                'os' => ($stats['os_distribution'] ?? 'Unknown') . ' ' . ($stats['os_version'] ?? ''),
                'cpu_cores' => $stats['cpu_cores'] ?? null,
                'memory_mb' => $stats['memory_total_mb'] ?? null,
                'disk_gb' => $stats['disk_total_gb'] ?? null,
                'uptime' => $stats['uptime_human'] ?? null,
            ];

            return json_encode([
                'success' => true,
                'message' => "Stats collected successfully for {$server->name}",
                'data' => $summary
            ], JSON_THROW_ON_ERROR);

        } catch (\Exception $e) {
            $this->logger->error(
                "Failed to collect stats for server {$server->name}: " . $e->getMessage(),
                'STATS',
                ['exception' => get_class($e), 'server_id' => $serverId]
            );

            throw new \Exception("Stats collection failed: " . $e->getMessage());
        }
    }

    /**
     * Handle stats collection via agent
     * Creates a task for the agent and waits for the result
     */
    private function handleViaAgent(Server $server, Job $job, JobQueue $queue): string
    {
        if (!$server->agentUuid) {
            throw new \Exception("Server {$server->name} is in agent mode but has no agent UUID");
        }

        $queue->updateProgress($job->id, 10, "Sending stats collection task to agent...");

        // Create a task for the agent
        $app = new \PhpBorg\Application();
        $connection = $app->getConnection();

        // Get agent_id from agents table using uuid
        $agent = $connection->fetchOne(
            'SELECT id FROM agents WHERE uuid = ?',
            [$server->agentUuid]
        );

        if (!$agent) {
            throw new \Exception("Agent not found for UUID: {$server->agentUuid}");
        }

        $agentId = (int)$agent['id'];

        // Insert task into agent_tasks table
        $taskPayload = [
            'server_id' => $server->id,
        ];

        $connection->executeUpdate(
            'INSERT INTO agent_tasks (agent_id, job_id, type, payload, status, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())',
            [
                $agentId,
                $job->id,
                'stats_collect',  // Must match Go agent task type
                json_encode($taskPayload),
                'pending'
            ]
        );

        $taskId = $connection->getLastInsertId();
        $this->logger->info("Created agent task #{$taskId} for stats collection", 'STATS');

        $queue->updateProgress($job->id, 20, "Waiting for agent to complete collection...");

        // Poll for task completion (max 2 minutes)
        $maxWait = 120;
        $waited = 0;
        $pollInterval = 2;

        while ($waited < $maxWait) {
            sleep($pollInterval);
            $waited += $pollInterval;

            // Check task status
            $task = $connection->fetchOne(
                'SELECT status, result, error FROM agent_tasks WHERE id = ?',
                [$taskId]
            );

            if (!$task) {
                throw new \Exception("Agent task #{$taskId} not found");
            }

            if ($task['status'] === 'completed') {
                $queue->updateProgress($job->id, 80, "Agent completed collection");

                $stats = json_decode($task['result'], true);
                if (!$stats) {
                    throw new \Exception("Invalid result from agent task");
                }

                // Save stats to database
                $this->statsRepository->saveStats($server->id, $stats);

                $queue->updateProgress($job->id, 100, "Stats collection completed");

                $this->logger->info("Stats collection completed for {$server->name} via agent", 'STATS', [
                    'server_id' => $server->id,
                    'metrics_collected' => count($stats)
                ]);

                // Return summary
                $summary = [
                    'server_id' => $server->id,
                    'server_name' => $server->name,
                    'metrics_collected' => count($stats),
                    'os' => ($stats['os_distribution'] ?? 'Unknown') . ' ' . ($stats['os_version'] ?? ''),
                    'cpu_cores' => $stats['cpu_cores'] ?? null,
                    'memory_mb' => $stats['memory_total_mb'] ?? null,
                    'disk_gb' => $stats['disk_total_gb'] ?? null,
                    'uptime' => $stats['uptime_human'] ?? null,
                ];

                return json_encode([
                    'success' => true,
                    'message' => "Stats collected successfully for {$server->name} via agent",
                    'data' => $summary
                ], JSON_THROW_ON_ERROR);
            }

            if ($task['status'] === 'failed') {
                throw new \Exception("Agent task failed: " . ($task['error'] ?? 'Unknown error'));
            }

            // Update progress based on wait time
            $progress = 20 + (int)(($waited / $maxWait) * 60);
            $queue->updateProgress($job->id, min($progress, 70), "Waiting for agent... ({$waited}s)");
        }

        throw new \Exception("Agent task timeout after {$maxWait} seconds");
    }

    public function supports(string $type): bool
    {
        return $type === 'server_stats_collect';
    }
}
