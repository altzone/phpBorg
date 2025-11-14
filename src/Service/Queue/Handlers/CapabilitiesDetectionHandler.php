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

            // Step 3: Detect Docker environment
            $queue->updateProgress($job->id, 70, "Detecting Docker containers and environment...");
            $docker = $this->detectDocker($server);
            $this->logger->info("Found " . ($docker['container_count'] ?? 0) . " Docker containers", 'CAPABILITIES');

            // Step 4: Get filesystem information
            $queue->updateProgress($job->id, 90, "Getting filesystem information...");
            $filesystem = $this->getFilesystemInfo($server);
            $this->logger->info("Filesystem info collected", 'CAPABILITIES');

            $capabilities = [
                'snapshots' => $snapshots,
                'databases' => $databases,
                'docker' => $docker,
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
                    "Detecting Docker containers and environment...",
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
     * Detect databases on server with complete information
     */
    private function detectDatabases(Server $server): array
    {
        $databases = [];

        try {
            // Check MySQL/MariaDB
            $mysqlInfo = $this->detectMysql($server);
            if ($mysqlInfo) {
                $databases[] = $mysqlInfo;
            }

            // Check PostgreSQL
            $pgInfo = $this->detectPostgresql($server);
            if ($pgInfo) {
                $databases[] = $pgInfo;
            }

            // Check MongoDB
            $mongoInfo = $this->detectMongodb($server);
            if ($mongoInfo) {
                $databases[] = $mongoInfo;
            }

            // Check Redis
            $redisInfo = $this->detectRedis($server);
            if ($redisInfo) {
                $databases[] = $redisInfo;
            }

        } catch (\Exception $e) {
            $this->logger->error("Error detecting databases: " . $e->getMessage(), 'CAPABILITIES');
        }

        return $databases;
    }

    /**
     * Detect MySQL/MariaDB with full details
     */
    private function detectMysql(Server $server): ?array
    {
        // Check if MySQL is installed
        $result = $this->sshExecutor->execute($server, 'which mysql 2>/dev/null', 5);
        if (!trim($result['stdout'])) {
            return null;
        }

        $info = [
            'type' => 'mysql',
            'name' => 'MySQL/MariaDB',
            'detected' => true,
            'running' => false,
            'version' => null,
            'datadir' => null,
            'datadir_detected' => false,
            'datadir_confidence' => 'unknown',  // 'high', 'medium', 'low', 'unknown'
            'datadir_candidates' => [],
            'datadir_size' => null,
            'volume' => null,
            'snapshot_capable' => false,
            'snapshot_recommended_size' => null
        ];

        try {
            // Get version
            $result = $this->sshExecutor->execute($server, 'mysql --version 2>/dev/null', 5);
            $info['version'] = trim($result['stdout']);

            // Check if MySQL is running
            $result = $this->sshExecutor->execute($server, 'systemctl is-active mysql 2>/dev/null || systemctl is-active mariadb 2>/dev/null || systemctl is-active mysqld 2>/dev/null', 5);
            $info['running'] = trim($result['stdout']) === 'active';

            if ($info['running']) {
                // Try to auto-detect working credentials
                $info['auth'] = $this->detectMysqlAuth($server);

                // Get datadir - try multiple methods with confidence tracking
                $datadir = null;
                $confidence = 'unknown';

                // Method 1: Direct SQL query (most reliable - HIGH confidence)
                $result = $this->sshExecutor->execute($server, 'mysql -e "SELECT @@datadir" 2>/dev/null | tail -n1', 10);
                $sqlDatadir = trim($result['stdout']);

                if ($sqlDatadir && !str_contains($sqlDatadir, 'ERROR') && !str_contains($sqlDatadir, 'datadir')) {
                    $datadir = $sqlDatadir;
                    $confidence = 'high';
                    $info['datadir_candidates'][] = ['path' => $sqlDatadir, 'method' => 'sql_query', 'confidence' => 'high'];
                }

                // Method 2: Parse my.cnf (MEDIUM confidence)
                if (!$datadir) {
                    $result = $this->sshExecutor->execute($server, 'grep -E "^datadir" /etc/mysql/my.cnf /etc/my.cnf /etc/mysql/mysql.conf.d/*.cnf 2>/dev/null | head -n1 | cut -d= -f2', 10);
                    $confDatadir = trim($result['stdout']);

                    if ($confDatadir) {
                        $datadir = $confDatadir;
                        $confidence = 'medium';
                        $info['datadir_candidates'][] = ['path' => $confDatadir, 'method' => 'config_file', 'confidence' => 'medium'];
                    }
                }

                // Method 3: Check running process (MEDIUM confidence)
                if (!$datadir) {
                    $result = $this->sshExecutor->execute($server, "ps aux | grep mysqld | grep -oP -- '--datadir=\\K[^ ]+' | head -n1", 10);
                    $procDatadir = trim($result['stdout']);

                    if ($procDatadir) {
                        $datadir = $procDatadir;
                        $confidence = 'medium';
                        $info['datadir_candidates'][] = ['path' => $procDatadir, 'method' => 'process_args', 'confidence' => 'medium'];
                    }
                }

                // NO FALLBACK - if we couldn't detect it, we mark it as unknown
                if ($datadir) {
                    $info['datadir'] = $datadir;
                    $info['datadir_detected'] = true;
                    $info['datadir_confidence'] = $confidence;
                } else {
                    // Add common locations as LOW confidence candidates for user validation
                    $info['datadir_candidates'][] = ['path' => '/var/lib/mysql', 'method' => 'common_location', 'confidence' => 'low'];
                    $info['datadir_candidates'][] = ['path' => '/var/lib/mysql-files', 'method' => 'common_location', 'confidence' => 'low'];
                }

                // Only proceed with size and volume detection if we have a valid datadir
                if ($datadir && $info['datadir_detected']) {
                    // Get datadir size
                    $result = $this->sshExecutor->execute($server, "du -sb {$datadir} 2>/dev/null | cut -f1", 10);
                    $sizeBytes = trim($result['stdout']);
                    if ($sizeBytes && is_numeric($sizeBytes)) {
                        $info['datadir_size'] = (int)$sizeBytes;
                        $info['datadir_size_human'] = $this->formatBytes((int)$sizeBytes);
                    }

                    // Detect volume and snapshot capability
                    $volumeInfo = $this->detectVolumeForPath($server, $datadir);
                    if ($volumeInfo) {
                        $info['volume'] = $volumeInfo;
                        $info['snapshot_capable'] = $volumeInfo['snapshot_capable'];

                        // Calculate recommended snapshot size
                        if ($volumeInfo['snapshot_capable'] && $info['datadir_size']) {
                            $snapshotCalc = $this->calculateSnapshotSize(
                                $info['datadir_size'],
                                $volumeInfo['vg_free_bytes'] ?? 0
                            );
                            $info['snapshot_size'] = [
                                'recommended_size' => $snapshotCalc['recommended_human'],
                                'datadir_size' => $this->formatBytes($info['datadir_size']),
                                'conservative' => $snapshotCalc['conservative_human'],
                                'aggressive' => $snapshotCalc['aggressive_human']
                            ];
                        }
                    }
                }
            }

        } catch (\Exception $e) {
            $this->logger->error("Error getting MySQL details: " . $e->getMessage(), 'CAPABILITIES');
        }

        return $info;
    }

    /**
     * Detect PostgreSQL with full details
     */
    private function detectPostgresql(Server $server): ?array
    {
        $result = $this->sshExecutor->execute($server, 'which psql 2>/dev/null', 5);
        if (!trim($result['stdout'])) {
            return null;
        }

        $info = [
            'type' => 'postgresql',
            'name' => 'PostgreSQL',
            'detected' => true,
            'running' => false,
            'version' => null,
            'datadir' => null,
            'datadir_detected' => false,
            'datadir_confidence' => 'unknown',
            'datadir_candidates' => [],
            'datadir_size' => null,
            'volume' => null,
            'snapshot_capable' => false,
            'snapshot_recommended_size' => null
        ];

        try {
            $result = $this->sshExecutor->execute($server, 'psql --version 2>/dev/null', 5);
            $info['version'] = trim($result['stdout']);

            $result = $this->sshExecutor->execute($server, 'systemctl is-active postgresql 2>/dev/null', 5);
            $info['running'] = trim($result['stdout']) === 'active';

            if ($info['running']) {
                // Try to auto-detect working credentials and clusters
                $info['auth'] = $this->detectPostgresqlAuth($server);

                // Get datadir - try multiple methods with confidence tracking
                $datadir = null;
                $confidence = 'unknown';

                // Method 1: SQL query (HIGH confidence)
                $result = $this->sshExecutor->execute($server, 'sudo -u postgres psql -t -c "SHOW data_directory" 2>/dev/null', 10);
                $sqlDatadir = trim($result['stdout']);

                if ($sqlDatadir && !str_contains($sqlDatadir, 'ERROR')) {
                    $datadir = $sqlDatadir;
                    $confidence = 'high';
                    $info['datadir_candidates'][] = ['path' => $sqlDatadir, 'method' => 'sql_query', 'confidence' => 'high'];
                }

                // Method 2: Check process args (MEDIUM confidence)
                if (!$datadir) {
                    $result = $this->sshExecutor->execute($server, "ps aux | grep postgres | grep -oP -- '-D\\s*\\K[^ ]+' | head -n1", 10);
                    $procDatadir = trim($result['stdout']);

                    if ($procDatadir) {
                        $datadir = $procDatadir;
                        $confidence = 'medium';
                        $info['datadir_candidates'][] = ['path' => $procDatadir, 'method' => 'process_args', 'confidence' => 'medium'];
                    }
                }

                // NO FALLBACK
                if ($datadir) {
                    $info['datadir'] = $datadir;
                    $info['datadir_detected'] = true;
                    $info['datadir_confidence'] = $confidence;

                    // Get size and volume info (increase timeout for large datadirs)
                    $result = $this->sshExecutor->execute($server, "du -sb {$datadir} 2>/dev/null | cut -f1", 60);
                    $sizeBytes = trim($result['stdout']);
                    if ($sizeBytes && is_numeric($sizeBytes)) {
                        $info['datadir_size'] = (int)$sizeBytes;
                        $info['datadir_size_human'] = $this->formatBytes((int)$sizeBytes);
                    }

                    $volumeInfo = $this->detectVolumeForPath($server, $datadir);
                    if ($volumeInfo) {
                        $info['volume'] = $volumeInfo;
                        $info['snapshot_capable'] = $volumeInfo['snapshot_capable'];

                        if ($volumeInfo['snapshot_capable'] && $info['datadir_size']) {
                            $snapshotCalc = $this->calculateSnapshotSize(
                                $info['datadir_size'],
                                $volumeInfo['vg_free_bytes'] ?? 0
                            );
                            $info['snapshot_size'] = [
                                'recommended_size' => $snapshotCalc['recommended_human'],
                                'datadir_size' => $this->formatBytes($info['datadir_size']),
                                'conservative' => $snapshotCalc['conservative_human'],
                                'aggressive' => $snapshotCalc['aggressive_human']
                            ];
                        }
                    }
                } else {
                    // Add common locations as LOW confidence candidates
                    $info['datadir_candidates'][] = ['path' => '/var/lib/postgresql', 'method' => 'common_location', 'confidence' => 'low'];
                    $info['datadir_candidates'][] = ['path' => '/var/lib/pgsql', 'method' => 'common_location', 'confidence' => 'low'];
                }
            }
        } catch (\Exception $e) {
            $this->logger->error("Error getting PostgreSQL details: " . $e->getMessage(), 'CAPABILITIES');
        }

        return $info;
    }

    /**
     * Detect MongoDB with full details
     */
    private function detectMongodb(Server $server): ?array
    {
        $result = $this->sshExecutor->execute($server, 'which mongod 2>/dev/null', 5);
        if (!trim($result['stdout'])) {
            return null;
        }

        $info = [
            'type' => 'mongodb',
            'name' => 'MongoDB',
            'detected' => true,
            'running' => false,
            'version' => null,
            'datadir' => null,
            'datadir_detected' => false,
            'datadir_confidence' => 'unknown',
            'datadir_candidates' => [],
            'datadir_size' => null,
            'volume' => null,
            'snapshot_capable' => false,
            'snapshot_recommended_size' => null
        ];

        try {
            $result = $this->sshExecutor->execute($server, 'mongod --version 2>/dev/null | head -n1', 5);
            $info['version'] = trim($result['stdout']);

            $result = $this->sshExecutor->execute($server, 'systemctl is-active mongod 2>/dev/null', 5);
            $info['running'] = trim($result['stdout']) === 'active';

            if ($info['running']) {
                // Get datadir - try multiple methods with confidence tracking
                $datadir = null;
                $confidence = 'unknown';

                // Method 1: Parse mongod.conf (MEDIUM confidence)
                $result = $this->sshExecutor->execute($server, 'grep -E "^\s*dbPath:" /etc/mongod.conf 2>/dev/null | awk \'{print $2}\'', 10);
                $confDatadir = trim($result['stdout']);

                if ($confDatadir) {
                    $datadir = $confDatadir;
                    $confidence = 'medium';
                    $info['datadir_candidates'][] = ['path' => $confDatadir, 'method' => 'config_file', 'confidence' => 'medium'];
                }

                // Method 2: Check process args (MEDIUM confidence)
                if (!$datadir) {
                    $result = $this->sshExecutor->execute($server, "ps aux | grep mongod | grep -oP -- '--dbpath[= ]\\K[^ ]+' | head -n1", 10);
                    $procDatadir = trim($result['stdout']);

                    if ($procDatadir) {
                        $datadir = $procDatadir;
                        $confidence = 'medium';
                        $info['datadir_candidates'][] = ['path' => $procDatadir, 'method' => 'process_args', 'confidence' => 'medium'];
                    }
                }

                // NO FALLBACK
                if ($datadir) {
                    $info['datadir'] = $datadir;
                    $info['datadir_detected'] = true;
                    $info['datadir_confidence'] = $confidence;

                    // Get size and volume info
                    $result = $this->sshExecutor->execute($server, "du -sb {$datadir} 2>/dev/null | cut -f1", 10);
                    $sizeBytes = trim($result['stdout']);
                    if ($sizeBytes && is_numeric($sizeBytes)) {
                        $info['datadir_size'] = (int)$sizeBytes;
                        $info['datadir_size_human'] = $this->formatBytes((int)$sizeBytes);
                    }

                    $volumeInfo = $this->detectVolumeForPath($server, $datadir);
                    if ($volumeInfo) {
                        $info['volume'] = $volumeInfo;
                        $info['snapshot_capable'] = $volumeInfo['snapshot_capable'];

                        if ($volumeInfo['snapshot_capable'] && $info['datadir_size']) {
                            $snapshotCalc = $this->calculateSnapshotSize(
                                $info['datadir_size'],
                                $volumeInfo['vg_free_bytes'] ?? 0
                            );
                            $info['snapshot_size'] = [
                                'recommended_size' => $snapshotCalc['recommended_human'],
                                'datadir_size' => $this->formatBytes($info['datadir_size']),
                                'conservative' => $snapshotCalc['conservative_human'],
                                'aggressive' => $snapshotCalc['aggressive_human']
                            ];
                        }
                    }
                } else {
                    // Add common locations as LOW confidence candidates
                    $info['datadir_candidates'][] = ['path' => '/var/lib/mongodb', 'method' => 'common_location', 'confidence' => 'low'];
                    $info['datadir_candidates'][] = ['path' => '/var/lib/mongo', 'method' => 'common_location', 'confidence' => 'low'];
                }
            }
        } catch (\Exception $e) {
            $this->logger->error("Error getting MongoDB details: " . $e->getMessage(), 'CAPABILITIES');
        }

        return $info;
    }

    /**
     * Detect Redis with full details
     */
    private function detectRedis(Server $server): ?array
    {
        $result = $this->sshExecutor->execute($server, 'which redis-server 2>/dev/null', 5);
        if (!trim($result['stdout'])) {
            return null;
        }

        $info = [
            'type' => 'redis',
            'name' => 'Redis',
            'detected' => true,
            'running' => false,
            'version' => null,
            'datadir' => '/var/lib/redis'
        ];

        try {
            $result = $this->sshExecutor->execute($server, 'redis-server --version 2>/dev/null', 5);
            $info['version'] = trim($result['stdout']);

            $result = $this->sshExecutor->execute($server, 'systemctl is-active redis 2>/dev/null || systemctl is-active redis-server 2>/dev/null', 5);
            $info['running'] = trim($result['stdout']) === 'active';
        } catch (\Exception $e) {
            $this->logger->error("Error getting Redis details: " . $e->getMessage(), 'CAPABILITIES');
        }

        return $info;
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
                    'INSERT INTO server_snapshot_capabilities (server_id, snapshot_type, available, details, created_at, updated_at)
                     VALUES (?, ?, ?, ?, NOW(), NOW())
                     ON DUPLICATE KEY UPDATE available = VALUES(available), details = VALUES(details), updated_at = NOW()',
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

    /**
     * Detect volume information for a given path
     * Identifies LVM, Btrfs, ZFS or regular filesystem
     */
    private function detectVolumeForPath(Server $server, string $path): ?array
    {
        try {
            // Get the mount point for this path
            $result = $this->sshExecutor->execute($server, "df -P '{$path}' 2>/dev/null | tail -n1", 10);
            $dfLine = trim($result['stdout']);

            if (!$dfLine) {
                return null;
            }

            $parts = preg_split('/\s+/', $dfLine);
            if (count($parts) < 6) {
                return null;
            }

            $device = $parts[0];
            $mountpoint = $parts[5];

            $volumeInfo = [
                'device' => $device,
                'mountpoint' => $mountpoint,
                'type' => 'standard',
                'snapshot_capable' => false
            ];

            // Check if it's LVM
            if (preg_match('#/dev/mapper/(.+?)-(.+)#', $device, $matches) ||
                preg_match('#/dev/([^/]+)/([^/]+)#', $device, $matches)) {

                $vgName = $matches[1] ?? null;
                $lvName = $matches[2] ?? null;

                if ($vgName && $lvName) {
                    // Get LVM details
                    $result = $this->sshExecutor->execute($server, "sudo lvs --noheadings --units b -o lv_name,vg_name,lv_size,lv_path '{$device}' 2>/dev/null", 10);
                    $lvsOutput = trim($result['stdout']);

                    if ($lvsOutput) {
                        $lvParts = preg_split('/\s+/', trim($lvsOutput));
                        if (count($lvParts) >= 3) {
                            $volumeInfo['type'] = 'lvm';
                            $volumeInfo['snapshot_capable'] = true;
                            $volumeInfo['vg_name'] = $vgName;
                            $volumeInfo['lv_name'] = $lvName;
                            $volumeInfo['lv_size'] = trim($lvParts[2], 'B');

                            // Get VG free space
                            $result = $this->sshExecutor->execute($server, "sudo vgs --noheadings --units b -o vg_free '{$vgName}' 2>/dev/null", 10);
                            $vgFree = trim($result['stdout']);
                            if ($vgFree) {
                                $volumeInfo['vg_free'] = trim($vgFree, 'B');
                                $volumeInfo['vg_free_bytes'] = (int)trim($vgFree, 'B');
                                $volumeInfo['vg_free_human'] = $this->formatBytes((int)trim($vgFree, 'B'));
                            }
                        }
                    }
                }
            }

            // Check if it's Btrfs
            $result = $this->sshExecutor->execute($server, "sudo btrfs filesystem show '{$mountpoint}' 2>/dev/null", 10);
            if ($result['exitCode'] === 0 && trim($result['stdout'])) {
                $volumeInfo['type'] = 'btrfs';
                $volumeInfo['snapshot_capable'] = true;

                // Get Btrfs usage
                $result = $this->sshExecutor->execute($server, "sudo btrfs filesystem usage '{$mountpoint}' 2>/dev/null | grep -E 'Free.*estimated' | head -n1", 10);
                $btrfsFree = trim($result['stdout']);
                if ($btrfsFree && preg_match('/(\d+\.?\d*)(G|M|K)iB/', $btrfsFree, $matches)) {
                    $volumeInfo['btrfs_free'] = $btrfsFree;
                }
            }

            // Check if it's ZFS
            $result = $this->sshExecutor->execute($server, "zfs list -H -o name,avail '{$mountpoint}' 2>/dev/null", 10);
            if ($result['exitCode'] === 0 && trim($result['stdout'])) {
                $volumeInfo['type'] = 'zfs';
                $volumeInfo['snapshot_capable'] = true;

                $zfsParts = preg_split('/\s+/', trim($result['stdout']));
                if (count($zfsParts) >= 2) {
                    $volumeInfo['zfs_dataset'] = $zfsParts[0];
                    $volumeInfo['zfs_avail'] = $zfsParts[1];
                }
            }

            return $volumeInfo;

        } catch (\Exception $e) {
            $this->logger->error("Error detecting volume for path {$path}: " . $e->getMessage(), 'CAPABILITIES');
            return null;
        }
    }

    /**
     * Calculate recommended snapshot size based on datadir size and available space
     */
    private function calculateSnapshotSize(int $datadirSizeBytes, int $vgFreeBytes): array
    {
        // Start with 20% of datadir size as a baseline
        $baselineSize = (int)($datadirSizeBytes * 0.20);

        // But at minimum 1GB
        $minSize = 1024 * 1024 * 1024; // 1GB

        // And at maximum 50% of VG free space
        $maxSize = (int)($vgFreeBytes * 0.50);

        // Calculate recommended size
        $recommendedSize = max($minSize, min($baselineSize, $maxSize));

        // Also provide conservative and aggressive options
        $conservative = max($minSize, (int)($datadirSizeBytes * 0.10));
        $aggressive = max($minSize, (int)($datadirSizeBytes * 0.30));

        return [
            'recommended' => $recommendedSize,
            'recommended_human' => $this->formatBytes($recommendedSize),
            'conservative' => $conservative,
            'conservative_human' => $this->formatBytes($conservative),
            'aggressive' => $aggressive,
            'aggressive_human' => $this->formatBytes($aggressive),
            'datadir_percent' => round(($recommendedSize / $datadirSizeBytes) * 100, 1),
            'vg_free_percent' => $vgFreeBytes > 0 ? round(($recommendedSize / $vgFreeBytes) * 100, 1) : 0
        ];
    }

    /**
     * Format bytes to human-readable format
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = floor(log($bytes, 1024));
        $power = min($power, count($units) - 1);

        return round($bytes / pow(1024, $power), 2) . ' ' . $units[$power];
    }

    /**
     * Detect MySQL authentication methods
     * Tries: 1) root without password, 2) debian.cnf credentials
     */
    private function detectMysqlAuth(Server $server): array
    {
        $auth = [
            'method' => null,
            'working' => false,
            'host' => 'localhost',
            'port' => 3306,
            'user' => null,
            'password' => null,
            'socket' => null
        ];

        // Method 1: Try root without password
        $result = $this->sshExecutor->execute($server, 'mysql -u root -e "SELECT 1" 2>&1', 5);
        if ($result['exitCode'] === 0 && !str_contains($result['stdout'], 'ERROR')) {
            $auth['method'] = 'root_no_password';
            $auth['working'] = true;
            $auth['user'] = 'root';
            $auth['password'] = '';
            return $auth;
        }

        // Method 2: Try debian.cnf (Debian/Ubuntu)
        $result = $this->sshExecutor->execute($server, 'cat /etc/mysql/debian.cnf 2>/dev/null', 5);
        if ($result['exitCode'] === 0 && !empty($result['stdout'])) {
            $debianCnf = $result['stdout'];

            // Extract user
            if (preg_match('/user\s*=\s*(.+)/', $debianCnf, $userMatch)) {
                $user = trim($userMatch[1]);

                // Extract password
                $password = '';
                if (preg_match('/password\s*=\s*(.+)/', $debianCnf, $passMatch)) {
                    $password = trim($passMatch[1]);
                }

                // Test credentials
                $testCmd = sprintf(
                    "mysql -u%s %s -e 'SELECT 1' 2>&1",
                    escapeshellarg($user),
                    $password ? '-p' . escapeshellarg($password) : ''
                );
                $testResult = $this->sshExecutor->execute($server, $testCmd, 5);

                if ($testResult['exitCode'] === 0 && !str_contains($testResult['stdout'], 'ERROR')) {
                    $auth['method'] = 'debian_cnf';
                    $auth['working'] = true;
                    $auth['user'] = $user;
                    $auth['password'] = $password;
                    return $auth;
                }
            }
        }

        // No working method found
        return $auth;
    }

    /**
     * Detect PostgreSQL authentication methods
     * Tries: 1) peer auth as postgres user, 2) pg_lscluster to list instances
     */
    private function detectPostgresqlAuth(Server $server): array
    {
        $auth = [
            'method' => null,
            'working' => false,
            'peer_auth' => false,
            'clusters' => [],
            'user' => null,
            'password' => null
        ];

        // Method 1: Try peer authentication as postgres user
        $result = $this->sshExecutor->execute($server, 'su - postgres -c "psql -c \'SELECT 1\'" 2>&1', 5);
        if ($result['exitCode'] === 0 && !str_contains($result['stdout'], 'ERROR')) {
            $auth['method'] = 'peer_auth';
            $auth['working'] = true;
            $auth['peer_auth'] = true;
            $auth['user'] = 'postgres';

            // List clusters if pg_lscluster is available
            $clusterResult = $this->sshExecutor->execute($server, 'su - postgres -c "pg_lscluster --no-header" 2>/dev/null', 5);
            if ($clusterResult['exitCode'] === 0 && !empty($clusterResult['stdout'])) {
                $lines = explode("\n", trim($clusterResult['stdout']));
                foreach ($lines as $line) {
                    $parts = preg_split('/\s+/', $line);
                    if (count($parts) >= 5) {
                        $auth['clusters'][] = [
                            'version' => $parts[0],
                            'cluster' => $parts[1],
                            'port' => $parts[2],
                            'status' => $parts[3],
                            'owner' => $parts[4],
                            'data_directory' => $parts[5] ?? null
                        ];
                    }
                }
            }
        }

        return $auth;
    }

    /**
     * Detect Docker environment for full backup capability
     * This detects containers, compose files, volumes, networks, and host config
     * to enable complete Docker environment restoration
     */
    private function detectDocker(Server $server): array
    {
        $dockerInfo = [
            'installed' => false,
            'running' => false,
            'version' => null,
            'containers' => [],
            'networks' => [],
            'volumes' => [],
            'compose_projects' => [],
            'host_config' => [
                'config_path' => '/etc/docker',
                'config_exists' => false,
                'daemon_json_exists' => false
            ]
        ];

        try {
            // Check if Docker is installed
            $result = $this->sshExecutor->execute($server, 'which docker 2>/dev/null', 5);
            if (!trim($result['stdout'])) {
                return $dockerInfo;
            }

            $dockerInfo['installed'] = true;

            // Get Docker version
            $result = $this->sshExecutor->execute($server, 'docker --version 2>/dev/null', 5);
            $dockerInfo['version'] = trim($result['stdout']);

            // Check if Docker daemon is running
            $result = $this->sshExecutor->execute($server, 'systemctl is-active docker 2>/dev/null', 5);
            $dockerInfo['running'] = trim($result['stdout']) === 'active';

            if (!$dockerInfo['running']) {
                return $dockerInfo;
            }

            // Detect Docker host configuration
            $dockerInfo['host_config'] = $this->detectDockerHostConfig($server);

            // Get ALL containers (running + stopped)
            $result = $this->sshExecutor->execute($server, 'docker ps -a --format "{{.ID}}|{{.Names}}|{{.Image}}|{{.Status}}|{{.State}}" 2>/dev/null', 15);
            $containerLines = trim($result['stdout']);

            if ($containerLines) {
                $lines = explode("\n", $containerLines);
                foreach ($lines as $line) {
                    $parts = explode('|', $line);
                    if (count($parts) >= 5) {
                        $containerId = $parts[0];
                        $containerName = $parts[1];

                        // Get detailed container info via inspect
                        $containerDetails = $this->inspectDockerContainer($server, $containerId);

                        $container = [
                            'id' => $containerId,
                            'name' => $containerName,
                            'image' => $parts[2],
                            'status' => $parts[3],
                            'state' => $parts[4],
                            'volumes' => $containerDetails['volumes'] ?? [],
                            'compose_project' => $containerDetails['compose_project'] ?? null,
                            'compose_file' => $containerDetails['compose_file'] ?? null,
                            'working_dir' => $containerDetails['working_dir'] ?? null,
                            'networks' => $containerDetails['networks'] ?? []
                        ];

                        $dockerInfo['containers'][] = $container;

                        // Track compose projects
                        if ($container['compose_project']) {
                            $projectKey = $container['compose_project'];
                            if (!isset($dockerInfo['compose_projects'][$projectKey])) {
                                $dockerInfo['compose_projects'][$projectKey] = [
                                    'name' => $container['compose_project'],
                                    'working_dir' => $container['working_dir'],
                                    'compose_file' => $container['compose_file'],
                                    'containers' => []
                                ];
                            }
                            $dockerInfo['compose_projects'][$projectKey]['containers'][] = $containerName;
                        }
                    }
                }
            }

            // Get Docker networks
            $dockerInfo['networks'] = $this->detectDockerNetworks($server);

            // Get Docker volumes
            $dockerInfo['volumes'] = $this->detectDockerVolumes($server);

            $dockerInfo['container_count'] = count($dockerInfo['containers']);
            $dockerInfo['network_count'] = count($dockerInfo['networks']);
            $dockerInfo['volume_count'] = count($dockerInfo['volumes']);
            $dockerInfo['compose_project_count'] = count($dockerInfo['compose_projects']);

        } catch (\Exception $e) {
            $this->logger->error("Error detecting Docker: " . $e->getMessage(), 'CAPABILITIES');
        }

        return $dockerInfo;
    }

    /**
     * Inspect a Docker container to get detailed information
     */
    private function inspectDockerContainer(Server $server, string $containerId): array
    {
        $details = [
            'volumes' => [],
            'compose_project' => null,
            'compose_file' => null,
            'working_dir' => null,
            'networks' => []
        ];

        try {
            // Get container inspect data
            $result = $this->sshExecutor->execute($server, "docker inspect {$containerId} 2>/dev/null", 10);
            $inspectJson = trim($result['stdout']);

            if ($inspectJson) {
                $inspectData = json_decode($inspectJson, true);
                if ($inspectData && is_array($inspectData) && isset($inspectData[0])) {
                    $container = $inspectData[0];

                    // Extract volumes (Mounts)
                    if (isset($container['Mounts']) && is_array($container['Mounts'])) {
                        foreach ($container['Mounts'] as $mount) {
                            if ($mount['Type'] === 'bind' && isset($mount['Source'])) {
                                // Only track host-mounted volumes (bind mounts)
                                $details['volumes'][] = [
                                    'type' => 'bind',
                                    'source' => $mount['Source'],
                                    'destination' => $mount['Destination'],
                                    'mode' => $mount['Mode'] ?? 'rw'
                                ];
                            } elseif ($mount['Type'] === 'volume') {
                                // Named Docker volumes (stored in /var/lib/docker/volumes)
                                $details['volumes'][] = [
                                    'type' => 'volume',
                                    'name' => $mount['Name'],
                                    'source' => $mount['Source'] ?? null,
                                    'destination' => $mount['Destination']
                                ];
                            }
                        }
                    }

                    // Extract compose project info from labels
                    if (isset($container['Config']['Labels'])) {
                        $labels = $container['Config']['Labels'];

                        // Docker Compose labels
                        if (isset($labels['com.docker.compose.project'])) {
                            $details['compose_project'] = $labels['com.docker.compose.project'];
                        }
                        if (isset($labels['com.docker.compose.project.working_dir'])) {
                            $details['working_dir'] = $labels['com.docker.compose.project.working_dir'];
                        }
                        if (isset($labels['com.docker.compose.project.config_files'])) {
                            $details['compose_file'] = $labels['com.docker.compose.project.config_files'];
                        }
                    }

                    // Extract networks
                    if (isset($container['NetworkSettings']['Networks'])) {
                        $details['networks'] = array_keys($container['NetworkSettings']['Networks']);
                    }
                }
            }

        } catch (\Exception $e) {
            $this->logger->error("Error inspecting container {$containerId}: " . $e->getMessage(), 'CAPABILITIES');
        }

        return $details;
    }

    /**
     * Detect Docker networks
     */
    private function detectDockerNetworks(Server $server): array
    {
        $networks = [];

        try {
            $result = $this->sshExecutor->execute($server, 'docker network ls --format "{{.ID}}|{{.Name}}|{{.Driver}}|{{.Scope}}" 2>/dev/null', 10);
            $lines = explode("\n", trim($result['stdout']));

            foreach ($lines as $line) {
                $parts = explode('|', $line);
                if (count($parts) >= 4) {
                    $networks[] = [
                        'id' => $parts[0],
                        'name' => $parts[1],
                        'driver' => $parts[2],
                        'scope' => $parts[3]
                    ];
                }
            }
        } catch (\Exception $e) {
            $this->logger->error("Error detecting Docker networks: " . $e->getMessage(), 'CAPABILITIES');
        }

        return $networks;
    }

    /**
     * Detect Docker volumes
     */
    private function detectDockerVolumes(Server $server): array
    {
        $volumes = [];

        try {
            $result = $this->sshExecutor->execute($server, 'docker volume ls --format "{{.Name}}|{{.Driver}}|{{.Mountpoint}}" 2>/dev/null', 10);
            $lines = explode("\n", trim($result['stdout']));

            foreach ($lines as $line) {
                $parts = explode('|', $line);
                if (count($parts) >= 2) {
                    $volumes[] = [
                        'name' => $parts[0],
                        'driver' => $parts[1],
                        'mountpoint' => $parts[2] ?? '/var/lib/docker/volumes/' . $parts[0]
                    ];
                }
            }
        } catch (\Exception $e) {
            $this->logger->error("Error detecting Docker volumes: " . $e->getMessage(), 'CAPABILITIES');
        }

        return $volumes;
    }

    /**
     * Detect Docker host configuration
     */
    private function detectDockerHostConfig(Server $server): array
    {
        $config = [
            'config_path' => '/etc/docker',
            'config_exists' => false,
            'daemon_json_exists' => false,
            'daemon_json_path' => '/etc/docker/daemon.json'
        ];

        try {
            // Check if /etc/docker exists
            $result = $this->sshExecutor->execute($server, 'test -d /etc/docker && echo "exists"', 5);
            $config['config_exists'] = trim($result['stdout']) === 'exists';

            // Check if daemon.json exists
            $result = $this->sshExecutor->execute($server, 'test -f /etc/docker/daemon.json && echo "exists"', 5);
            $config['daemon_json_exists'] = trim($result['stdout']) === 'exists';

        } catch (\Exception $e) {
            $this->logger->error("Error detecting Docker host config: " . $e->getMessage(), 'CAPABILITIES');
        }

        return $config;
    }
}