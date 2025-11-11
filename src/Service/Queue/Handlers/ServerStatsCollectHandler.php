<?php

declare(strict_types=1);

namespace PhpBorg\Service\Queue\Handlers;

use PhpBorg\Entity\Job;
use PhpBorg\Logger\LoggerInterface;
use PhpBorg\Repository\ServerRepository;
use PhpBorg\Repository\ServerStatsRepository;
use PhpBorg\Service\Queue\JobQueue;
use PhpBorg\Service\Server\ServerStatsCollector;

/**
 * Handler for server stats collection jobs
 * Collects system metrics from remote servers via SSH
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

        $this->logger->info("Starting stats collection for server: {$server->name}", 'STATS');
        $queue->updateProgress($job->id, 10, "Connecting to server {$server->name}...");

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

    public function supports(string $type): bool
    {
        return $type === 'server_stats_collect';
    }
}
