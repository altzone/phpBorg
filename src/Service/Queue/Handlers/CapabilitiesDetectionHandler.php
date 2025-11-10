<?php

declare(strict_types=1);

namespace PhpBorg\Service\Queue\Handlers;

use PhpBorg\Entity\Job;
use PhpBorg\Entity\Server;
use PhpBorg\Logger\LoggerInterface;
use PhpBorg\Repository\ServerRepository;
use PhpBorg\Service\Queue\JobQueue;
use PhpBorg\Service\Server\SshExecutor;

/**
 * Handler for server capabilities detection jobs
 * Detects snapshots, databases, and filesystem information
 */
final class CapabilitiesDetectionHandler implements JobHandlerInterface
{
    public function __construct(
        private readonly ServerRepository $serverRepository,
        private readonly SshExecutor $sshExecutor,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Handle capabilities detection job
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

        $this->logger->info("Starting capabilities detection for server: {$server->name}", 'CAPABILITIES');
        $queue->updateProgress($job->id, 10, "Connecting to server {$server->name}...");

        try {
            // Step 1: Detect snapshot capabilities
            $queue->updateProgress($job->id, 30, "Detecting snapshot capabilities...");
            $snapshots = $this->detectSnapshotCapabilities($server);
            $this->logger->info("Found " . count($snapshots) . " snapshot methods", 'CAPABILITIES');

            // Step 2: Detect databases
            $queue->updateProgress($job->id, 60, "Detecting databases...");
            $databases = $this->detectDatabases($server);
            $this->logger->info("Found " . count($databases) . " databases", 'CAPABILITIES');

            // Step 3: Get filesystem information
            $queue->updateProgress($job->id, 90, "Getting filesystem information...");
            $filesystem = $this->getFilesystemInfo($server);
            $this->logger->info("Filesystem info collected", 'CAPABILITIES');

            $capabilities = [
                'snapshots' => $snapshots,
                'databases' => $databases,
                'filesystem' => $filesystem
            ];

            // Store in database for future use
            $this->storeCapabilities($serverId, $capabilities);

            $queue->updateProgress($job->id, 100, "Detection complete");
            $this->logger->info("Capabilities detection completed for {$server->name}", 'CAPABILITIES');

            // Return structured JSON with both progress messages and data
            $result = [
                'progress_messages' => [
                    "Connecting to server {$server->name}...",
                    "Detecting snapshot capabilities...",
                    "Detecting databases...",
                    "Getting filesystem information...",
                    "Detection complete"
                ],
                'data' => $capabilities
            ];

            return json_encode($result);

        } catch (\Exception $e) {
            $this->logger->error("Capabilities detection failed: {$e->getMessage()}", 'CAPABILITIES');
            throw new \Exception("Detection failed: {$e->getMessage()}");
        }
    }

    /**
     * Detect snapshot capabilities on server
     */
    private function detectSnapshotCapabilities(Server $server): array
    {
        $capabilities = [];

        try {
            // Check LVM
            $result = $this->sshExecutor->execute($server, 'which lvcreate 2>/dev/null', 5);
            if (trim($result['stdout'])) {
                $result = $this->sshExecutor->execute($server, 'sudo lvs --noheadings -o lv_name,vg_name,lv_path 2>/dev/null', 10);
                $lvs = trim($result['stdout']);
                if ($lvs) {
                    $volumeCount = count(array_filter(explode("\n", $lvs)));
                    $capabilities[] = [
                        'type' => 'lvm',
                        'name' => 'LVM Snapshot',
                        'available' => true,
                        'description' => 'Logical Volume Manager snapshots for consistent backups',
                        'details' => $volumeCount . ' volume(s) available'
                    ];
                }
            }

            // Check ZFS
            $result = $this->sshExecutor->execute($server, 'which zfs 2>/dev/null', 5);
            if (trim($result['stdout'])) {
                $result = $this->sshExecutor->execute($server, 'zfs list -H -o name 2>/dev/null', 10);
                $datasets = trim($result['stdout']);
                if ($datasets) {
                    $datasetCount = count(array_filter(explode("\n", $datasets)));
                    $capabilities[] = [
                        'type' => 'zfs',
                        'name' => 'ZFS Snapshot',
                        'available' => true,
                        'description' => 'ZFS dataset snapshots with instant creation',
                        'details' => $datasetCount . ' dataset(s) available'
                    ];
                }
            }

            // Check Btrfs
            $result = $this->sshExecutor->execute($server, 'which btrfs 2>/dev/null', 5);
            if (trim($result['stdout'])) {
                $result = $this->sshExecutor->execute($server, 'sudo btrfs subvolume list / 2>/dev/null', 10);
                $subvolumes = trim($result['stdout']);
                if ($subvolumes) {
                    $capabilities[] = [
                        'type' => 'btrfs',
                        'name' => 'Btrfs Snapshot',
                        'available' => true,
                        'description' => 'Btrfs subvolume snapshots with CoW efficiency',
                        'details' => 'Btrfs filesystem detected'
                    ];
                }
            }

        } catch (\Exception $e) {
            $this->logger->error("Error detecting snapshots: " . $e->getMessage(), 'CAPABILITIES');
        }

        return $capabilities;
    }

    /**
     * Detect databases on server
     */
    private function detectDatabases(Server $server): array
    {
        $databases = [];

        try {
            // Check MySQL/MariaDB
            $result = $this->sshExecutor->execute($server, 'which mysql 2>/dev/null', 5);
            if (trim($result['stdout'])) {
                $result = $this->sshExecutor->execute($server, 'mysql --version 2>/dev/null | head -n1', 5);
                $version = trim($result['stdout']);
                $databases[] = [
                    'type' => 'mysql',
                    'name' => 'MySQL/MariaDB',
                    'detected' => true,
                    'version' => $version
                ];
            }

            // Check PostgreSQL
            $result = $this->sshExecutor->execute($server, 'which psql 2>/dev/null', 5);
            if (trim($result['stdout'])) {
                $result = $this->sshExecutor->execute($server, 'psql --version 2>/dev/null | head -n1', 5);
                $version = trim($result['stdout']);
                $databases[] = [
                    'type' => 'postgresql',
                    'name' => 'PostgreSQL',
                    'detected' => true,
                    'version' => $version
                ];
            }

            // Check MongoDB
            $result = $this->sshExecutor->execute($server, 'which mongod 2>/dev/null', 5);
            if (trim($result['stdout'])) {
                $result = $this->sshExecutor->execute($server, 'mongod --version 2>/dev/null | head -n1', 5);
                $version = trim($result['stdout']);
                $databases[] = [
                    'type' => 'mongodb',
                    'name' => 'MongoDB',
                    'detected' => true,
                    'version' => $version
                ];
            }

        } catch (\Exception $e) {
            $this->logger->error("Error detecting databases: " . $e->getMessage(), 'CAPABILITIES');
        }

        return $databases;
    }

    /**
     * Get filesystem information
     */
    private function getFilesystemInfo(Server $server): array
    {
        try {
            $result = $this->sshExecutor->execute($server, 'df -h 2>/dev/null', 10);
            $df = $result['stdout'];
            $mounts = [];

            if ($df) {
                $lines = explode("\n", $df);
                foreach ($lines as $i => $line) {
                    if ($i === 0) continue; // Skip header
                    $parts = preg_split('/\s+/', trim($line));
                    if (count($parts) >= 6) {
                        $mounts[] = [
                            'filesystem' => $parts[0],
                            'size' => $parts[1],
                            'used' => $parts[2],
                            'available' => $parts[3],
                            'use_percent' => $parts[4],
                            'mount' => $parts[5]
                        ];
                    }
                }
            }

            return ['mounts' => $mounts];

        } catch (\Exception $e) {
            $this->logger->error("Error getting filesystem info: " . $e->getMessage(), 'CAPABILITIES');
            return ['mounts' => []];
        }
    }

    /**
     * Store capabilities in database
     */
    private function storeCapabilities(int $serverId, array $capabilities): void
    {
        // ServerRepository doesn't expose connection, use Application to get it
        $app = new \PhpBorg\Application();
        $connection = $app->getConnection();

        try {
            // Store snapshot capabilities
            foreach ($capabilities['snapshots'] as $snapshot) {
                $connection->executeUpdate(
                    'INSERT INTO server_snapshot_capabilities (server_id, snapshot_type, available, details) 
                     VALUES (?, ?, ?, ?) 
                     ON DUPLICATE KEY UPDATE available = VALUES(available), details = VALUES(details)',
                    [
                        $serverId,
                        $snapshot['type'],
                        $snapshot['available'] ? 1 : 0,
                        json_encode($snapshot)
                    ]
                );
            }

            // Update server metadata with detection results
            $connection->executeUpdate(
                'UPDATE servers SET 
                    capabilities_detected = 1,
                    capabilities_data = ?,
                    capabilities_detected_at = NOW()
                 WHERE id = ?',
                [
                    json_encode($capabilities),
                    $serverId
                ]
            );

        } catch (\Exception $e) {
            $this->logger->error("Failed to store capabilities: " . $e->getMessage(), 'CAPABILITIES');
        }
    }
}