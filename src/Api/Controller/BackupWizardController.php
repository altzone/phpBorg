<?php

declare(strict_types=1);

namespace PhpBorg\Api\Controller;

use PhpBorg\Application;
use PhpBorg\Repository\ServerRepository;
use PhpBorg\Repository\StoragePoolRepository;
use PhpBorg\Repository\BorgRepositoryRepository;
use PhpBorg\Repository\BackupSourceRepository;
use PhpBorg\Repository\BackupJobRepository;
use PhpBorg\Repository\BackupScheduleRepository;
use PhpBorg\Repository\JobRepository;
use PhpBorg\Repository\DatabaseInfoRepository;
use PhpBorg\Exception\PhpBorgException;
use PhpBorg\Service\Server\SshExecutor;
use PhpBorg\Service\Queue\JobQueue;
use PhpBorg\Service\Repository\EncryptionService;

/**
 * Backup Wizard API controller
 * Provides endpoints for the backup configuration wizard
 */
class BackupWizardController extends BaseController
{
    private readonly ServerRepository $serverRepository;
    private readonly StoragePoolRepository $storagePoolRepository;
    private readonly BorgRepositoryRepository $repositoryRepository;
    private readonly BackupSourceRepository $sourceRepository;
    private readonly BackupJobRepository $jobRepository;
    private readonly BackupScheduleRepository $scheduleRepository;
    private readonly JobRepository $queueJobRepository;
    private readonly JobQueue $jobQueue;
    private readonly SshExecutor $sshExecutor;
    private readonly EncryptionService $encryptionService;
    private readonly DatabaseInfoRepository $databaseInfoRepository;

    public function __construct(Application $app)
    {
        $this->serverRepository = new ServerRepository($app->getConnection());
        $this->storagePoolRepository = new StoragePoolRepository($app->getConnection());
        $this->repositoryRepository = new BorgRepositoryRepository($app->getConnection());
        $this->sourceRepository = new BackupSourceRepository($app->getConnection());
        $this->jobRepository = new BackupJobRepository($app->getConnection());
        $this->scheduleRepository = new BackupScheduleRepository($app->getConnection());
        $this->queueJobRepository = new JobRepository($app->getConnection());
        $this->jobQueue = $app->getJobQueue();
        // Use the application's configured logger
        $this->sshExecutor = new SshExecutor($app->getLogger());
        $this->encryptionService = new EncryptionService($app->getConfig());
        $this->databaseInfoRepository = $app->getDatabaseInfoRepository();
    }

    /**
     * Detect server capabilities (snapshot methods, databases, etc.)
     * Creates a job for background detection
     * GET /api/backup-wizard/capabilities/{serverId}
     */
    public function capabilities(): void
    {
        try {
            // Get serverId from route parameters
            $params = $_SERVER['ROUTE_PARAMS'] ?? [];
            $serverId = (int)($params['serverId'] ?? 0);
            
            if (!$serverId) {
                $this->error('Server ID is required', 400);
                return;
            }
            
            $server = $this->serverRepository->findById($serverId);
            if (!$server) {
                $this->error('Server not found', 404);
                return;
            }

            // Get user from JWT
            $user = $_SERVER['USER'] ?? null;
            $userId = $user ? $user->id : null;

            // Create a job for capabilities detection
            $jobId = $this->jobQueue->push(
                'capabilities_detection',
                ['server_id' => $serverId],
                'default',
                1,  // Only 1 attempt, fast operation
                $userId
            );

            $this->success([
                'job_id' => $jobId,
                'message' => 'Detection job created',
                'status' => 'pending'
            ]);
        } catch (\Exception $e) {
            // Log error and return proper error response
            error_log("BackupWizard capabilities error: " . $e->getMessage());
            $this->error('Failed to create detection job: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Check job status and get results
     * GET /api/backup-wizard/job-status/{jobId}
     */
    public function jobStatus(): void
    {
        $params = $_SERVER['ROUTE_PARAMS'] ?? [];
        $jobId = (int)($params['jobId'] ?? 0);
        
        if (!$jobId) {
            throw new PhpBorgException('Job ID is required', 400);
        }

        $job = $this->queueJobRepository->findById($jobId);
        if (!$job) {
            throw new PhpBorgException('Job not found', 404);
        }

        $response = [
            'job_id' => $job->id,
            'status' => $job->status,
            'progress' => $job->progress,
            'progress_message' => null, // Could extract from output if needed
            'created_at' => $job->createdAt->format('Y-m-d H:i:s')
        ];

        if ($job->status === 'completed') {
            // Output is already in JSON format with progress_messages and data
            if ($job->output) {
                $decoded = json_decode($job->output, true);
                if ($decoded && isset($decoded['data'])) {
                    $response['data'] = $decoded['data'];
                    $response['progress_messages'] = $decoded['progress_messages'] ?? [];
                } else {
                    // Fallback for old format (try to extract JSON from last line)
                    $lines = explode("\n", $job->output);
                    $lastLine = trim(end($lines));
                    if ($lastLine && $lastLine[0] === '{') {
                        $response['data'] = json_decode($lastLine, true);
                    } else {
                        $response['data'] = null;
                    }
                }
            } else {
                $response['data'] = null;
            }
        } elseif ($job->status === 'failed') {
            $response['error'] = $job->error;
        }

        $this->success($response);
    }

    /**
     * Auto-detect MySQL credentials
     * POST /api/backup-wizard/detect-mysql
     */
    public function detectMySQL(): void
    {
        $data = $this->getJsonBody();
        $serverId = $data['server_id'] ?? 0;
        $server = $this->serverRepository->findById($serverId);
        
        if (!$server) {
            throw new PhpBorgException('Server not found', 404);
        }

        try {
            // Try to read debian.cnf
            $result = $this->sshExecutor->execute($server, 'cat /etc/mysql/debian.cnf 2>/dev/null', 10);
            $debianCnf = $result['stdout'];
            if ($debianCnf && strpos($debianCnf, 'user') !== false) {
                preg_match('/user\s*=\s*(.+)/', $debianCnf, $userMatch);
                preg_match('/password\s*=\s*(.+)/', $debianCnf, $passMatch);
                
                if ($userMatch && $passMatch) {
                    $this->success([
                        'data' => [
                            'detected' => true,
                            'source' => '/etc/mysql/debian.cnf',
                            'username' => trim($userMatch[1]),
                            'password' => trim($passMatch[1]),
                            'host' => 'localhost',
                            'port' => 3306
                        ]
                    ]);
                    return;
                }
            }
            
            // Try .my.cnf in home directory
            $result = $this->sshExecutor->execute($server, 'cat ~/.my.cnf 2>/dev/null', 10);
            $myCnf = $result['stdout'];
            if ($myCnf && strpos($myCnf, 'user') !== false) {
                preg_match('/user\s*=\s*(.+)/', $myCnf, $userMatch);
                preg_match('/password\s*=\s*(.+)/', $myCnf, $passMatch);
                
                if ($userMatch) {
                    $this->success([
                        'data' => [
                            'detected' => true,
                            'source' => '~/.my.cnf',
                            'username' => trim($userMatch[1]),
                            'password' => isset($passMatch[1]) ? trim($passMatch[1]) : '',
                            'host' => 'localhost',
                            'port' => 3306
                        ]
                    ]);
                    return;
                }
            }
            
        } catch (\Exception $e) {
            // SSH connection failed
        }

        $this->success([
            'data' => [
                'detected' => false,
                'message' => 'Could not auto-detect MySQL credentials'
            ]
        ]);
    }

    /**
     * Test database connection
     * POST /api/backup-wizard/test-db-connection
     */
    public function testDatabaseConnection(): void
    {
        $data = $this->getJsonBody();
        $serverId = $data['server_id'] ?? 0;
        $server = $this->serverRepository->findById($serverId);
        
        if (!$server) {
            throw new PhpBorgException('Server not found', 404);
        }

        $dbType = $data['db_type'] ?? 'mysql';
        $host = $data['host'] ?? 'localhost';
        $port = $data['port'] ?? 3306;
        $username = $data['username'] ?? '';
        $password = $data['password'] ?? '';

        try {
            if ($dbType === 'mysql' || $dbType === 'mariadb') {
                $command = sprintf(
                    'mysql -h%s -P%d -u%s %s -e "SELECT 1" 2>&1',
                    escapeshellarg($host),
                    $port,
                    escapeshellarg($username),
                    $password ? '-p' . escapeshellarg($password) : ''
                );
                
                $result = $this->sshExecutor->execute($server, $command, 10);
                $success = strpos($result['stdout'] . $result['stderr'], 'ERROR') === false;
                
                $this->success([
                    'data' => [
                        'connected' => $success,
                        'message' => $success ? 'Connection successful' : 'Connection failed'
                    ]
                ]);
                return;
            }
            
            // Add other database types as needed
            
        } catch (\Exception $e) {
            $this->error($e->getMessage(), 500);
            return;
        }
    }

    /**
     * Create complete backup configuration
     * POST /api/backup-wizard/create-backup-chain
     */
    public function createBackupChain(): void
    {
        try {
            $data = $this->getJsonBody();

            $connection = $this->repositoryRepository->getConnection();

            $connection->beginTransaction();
            
            // 1. Create backup source
            $source = $this->sourceRepository->create([
                'name' => $data['source_name'] ?? $data['repository_name'] . ' Source',
                'type' => $data['backup_type'],
                'server_id' => $data['server_id'],
                'config' => $data['source_config'],
                'paths' => $data['paths'] ?? null,
                'exclude_patterns' => $data['exclude_patterns'] ?? null,
                'active' => true
            ]);
            
            // 2. Create repository
            // Generate unique repo ID
            $repoId = uniqid('repo_');

            // Get storage pool path and server name
            $storagePoolPath = '/opt/backups'; // Default
            if (isset($data['storage_pool_id'])) {
                $pool = $this->storagePoolRepository->findById((int)$data['storage_pool_id']);
                if ($pool) {
                    $storagePoolPath = $pool->path;
                }
            }

            // Get server name for directory structure
            $server = $this->serverRepository->findById((int)$data['server_id']);
            $serverName = $server ? preg_replace('/[^a-zA-Z0-9-_]/', '_', $server->name) : 'unknown';

            // Create repository path: <storage_pool>/<server_name>/<repository_name>
            $repoPath = sprintf(
                '%s/%s/%s',
                rtrim($storagePoolPath, '/'),
                $serverName,
                preg_replace('/[^a-zA-Z0-9-_]/', '_', $data['repository_name'])
            );

            // Generate passphrase if not provided and encryption is enabled
            $passphrase = $data['passphrase'] ?? '';
            if (empty($passphrase) && ($data['encryption'] ?? 'repokey-blake2') !== 'none') {
                $passphrase = $this->encryptionService->generatePassphrase();
            }

            $repositoryId = $this->repositoryRepository->create(
                serverId: (int)$data['server_id'],
                repoId: $repoId,
                type: $data['backup_type'],
                retention: $data['retention']['keep_daily'] ?? 7, // Legacy retention field
                encryption: $data['encryption'] ?? 'repokey-blake2',
                passphrase: $passphrase,
                repoPath: $repoPath,
                compression: $data['compression'] ?? 'lz4',
                rateLimit: $data['rate_limit'] ?? 0,
                backupPath: $data['paths'][0] ?? '/',
                exclude: is_array($data['exclude_patterns']) 
                    ? implode(',', $data['exclude_patterns']) 
                    : ($data['exclude_patterns'] ?? null),
                keepDaily: $data['retention']['keep_daily'] ?? 7,
                keepWeekly: $data['retention']['keep_weekly'] ?? 4,
                keepMonthly: $data['retention']['keep_monthly'] ?? 6,
                keepYearly: $data['retention']['keep_yearly'] ?? 1
            );
            
            // Update repository with storage pool and snapshot method
            if (isset($data['storage_pool_id'])) {
                $connection->executeUpdate(
                    'UPDATE repository SET storage_pool_id = ?, snapshot_method = ? WHERE id = ?',
                    [
                        $data['storage_pool_id'],
                        $data['snapshot_method'] ?? 'none',
                        $repositoryId
                    ]
                );
            }

            // 2.5. Create DatabaseInfo if this is a database backup
            $databaseTypes = ['mysql', 'mariadb', 'postgresql', 'postgres', 'mongodb', 'docker'];
            if (in_array($data['backup_type'], $databaseTypes)) {
                $dbInfoId = $this->createDatabaseInfo(
                    $server,
                    $data['backup_type'],
                    $data['source_config'] ?? [],
                    $repoId
                );
            }

            // 3. Create backup job
            // Convert time format from HH:MM to HH:MM:SS if needed
            $scheduleTime = null;
            if (isset($data['schedule_time']) && $data['schedule_time']) {
                // Log the incoming time for debugging
                error_log("Incoming schedule_time: " . $data['schedule_time']);
                
                // Remove any duplicate :00 if present
                $scheduleTime = $data['schedule_time'];
                // If it's HH:MM format, add :00
                if (preg_match('/^\d{2}:\d{2}$/', $scheduleTime)) {
                    $scheduleTime .= ':00';
                }
                // If it's already HH:MM:SS:SS (duplicated), fix it
                elseif (preg_match('/^\d{2}:\d{2}:\d{2}:\d{2}$/', $scheduleTime)) {
                    $scheduleTime = substr($scheduleTime, 0, 8); // Take only HH:MM:SS
                }
                
                error_log("Converted schedule_time: " . $scheduleTime);
            }
            
            $jobId = $this->jobRepository->create(
                name: $data['job_name'] ?? $data['repository_name'],
                repositoryId: $repositoryId,
                scheduleType: $data['schedule_type'] ?? 'manual',
                scheduleTime: $scheduleTime,
                scheduleDayOfWeek: $data['schedule_day_of_week'] ?? null,
                scheduleDayOfMonth: $data['schedule_day_of_month'] ?? null,
                cronExpression: $data['cron_expression'] ?? null,
                enabled: $data['enabled'] ?? true,
                notifyOnSuccess: $data['notify_on_success'] ?? false,
                notifyOnFailure: $data['notify_on_failure'] ?? true
            );
            
            // Link job to source
            $connection->executeUpdate(
                'UPDATE backup_jobs SET source_id = ? WHERE id = ?',
                [$source->id, $jobId]
            );
            
            // 4. Create schedule if multi-day selection provided
            if (isset($data['weekdays_array']) || isset($data['monthdays_array'])) {
                $scheduleData = [
                    'job_id' => $jobId,
                    'type' => $data['schedule_type'],
                    'time' => $data['schedule_time'] ?? '00:00:00',
                ];
                
                if (isset($data['weekdays_array'])) {
                    $scheduleData['weekdays'] = $this->scheduleRepository->calculateWeekdaysBitmap($data['weekdays_array']);
                }
                
                if (isset($data['monthdays_array'])) {
                    $scheduleData['monthdays'] = $this->scheduleRepository->calculateMonthdaysBitmap($data['monthdays_array']);
                }
                
                $this->scheduleRepository->create($scheduleData);
            }
            
            $connection->commit();
            
            // 5. Initialize Borg repository if requested
            if ($data['initialize_repo'] ?? false) {
                // This would trigger a job to initialize the Borg repository
                // For now, we'll just mark it as pending
            }
            
            // 6. Run test backup if requested
            if ($data['run_test_backup'] ?? false) {
                // This would trigger a test backup job
                // For now, we'll just mark it as pending
            }
            
            $response = [
                'message' => 'Backup configuration created successfully',
                'data' => [
                    'source_id' => $source->id,
                    'repository_id' => $repositoryId,
                    'job_id' => $jobId
                ]
            ];
            
            // Include generated passphrase in response if it was auto-generated
            if (empty($data['passphrase']) && ($data['encryption'] ?? 'repokey-blake2') !== 'none') {
                $response['data']['generated_passphrase'] = $passphrase;
                $response['data']['passphrase_warning'] = 'IMPORTANT: Save this passphrase securely! It cannot be recovered.';
            }
            
            $this->success($response);
            return;
            
        } catch (\Exception $e) {
            if (isset($connection)) {
                $connection->rollBack();
            }
            
            // Log error for debugging
            error_log("BackupWizard createBackupChain error: " . $e->getMessage());
            
            throw new PhpBorgException('Failed to create backup configuration: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get backup templates
     * GET /api/backup-wizard/templates
     */
    public function templates(): void
    {
        $result = $this->repositoryRepository->getConnection()->query(
            'SELECT * FROM backup_templates ORDER BY is_system DESC, name ASC'
        );
        
        $templates = [];
        foreach ($result as $row) {
            $templates[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'description' => $row['description'],
                'icon' => $row['icon'],
                'backup_type' => $row['backup_type'],
                'source_config' => json_decode($row['source_config'], true),
                'exclude_patterns' => json_decode($row['exclude_patterns'], true),
                'retention_policy' => json_decode($row['retention_policy'], true),
                'compression' => $row['compression'],
                'encryption' => $row['encryption'],
                'is_system' => (bool)$row['is_system']
            ];
        }
        
        $this->success([
            'data' => $templates
        ]);
    }

    /**
     * Detect snapshot capabilities on server
     */
    private function detectSnapshotCapabilities($server): array
    {
        $capabilities = [];
        $app = new \PhpBorg\Application();
        $logger = $app->getLogger();
        
        try {
            $logger->debug("Starting snapshot detection for server: {$server->name}", 'BackupWizard');
            
            // Check LVM
            $logger->debug("Checking for LVM...", 'BackupWizard');
            $result = $this->sshExecutor->execute($server, 'which lvcreate 2>/dev/null', 5);
            if (trim($result['stdout'])) {
                $logger->debug("LVM tools found, checking volumes...", 'BackupWizard');
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
                    $logger->info("LVM detected with {$volumeCount} volumes", 'BackupWizard');
                }
            }
            
            // Check ZFS
            $logger->debug("Checking for ZFS...", 'BackupWizard');
            $result = $this->sshExecutor->execute($server, 'which zfs 2>/dev/null', 5);
            if (trim($result['stdout'])) {
                $logger->debug("ZFS tools found, checking datasets...", 'BackupWizard');
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
                    $logger->info("ZFS detected with {$datasetCount} datasets", 'BackupWizard');
                }
            }
            
            // Check Btrfs
            $logger->debug("Checking for Btrfs...", 'BackupWizard');
            $result = $this->sshExecutor->execute($server, 'which btrfs 2>/dev/null', 5);
            if (trim($result['stdout'])) {
                $logger->debug("Btrfs tools found, checking subvolumes...", 'BackupWizard');
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
                    $logger->info("Btrfs detected", 'BackupWizard');
                }
            }
            
            $logger->info("Snapshot detection complete. Found " . count($capabilities) . " snapshot methods", 'BackupWizard');
            
        } catch (\Exception $e) {
            $logger->error("SSH connection failed during snapshot detection: " . $e->getMessage(), 'BackupWizard');
        }
        
        return $capabilities;
    }

    /**
     * Detect databases on server
     */
    private function detectDatabases($server): array
    {
        $databases = [];
        $app = new \PhpBorg\Application();
        $logger = $app->getLogger();
        
        try {
            $logger->debug("Starting database detection for server: {$server->name}", 'BackupWizard');
            
            // Check MySQL/MariaDB
            $logger->debug("Checking for MySQL/MariaDB...", 'BackupWizard');
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
                $logger->info("MySQL/MariaDB detected: {$version}", 'BackupWizard');
            }
            
            // Check PostgreSQL
            $logger->debug("Checking for PostgreSQL...", 'BackupWizard');
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
                $logger->info("PostgreSQL detected: {$version}", 'BackupWizard');
            }
            
            // Check MongoDB
            $logger->debug("Checking for MongoDB...", 'BackupWizard');
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
                $logger->info("MongoDB detected: {$version}", 'BackupWizard');
            }
            
            $logger->info("Database detection complete. Found " . count($databases) . " database(s)", 'BackupWizard');
            
        } catch (\Exception $e) {
            $logger->error("SSH connection failed during database detection: " . $e->getMessage(), 'BackupWizard');
        }
        
        return $databases;
    }

    /**
     * Get filesystem information
     */
    private function getFilesystemInfo($server): array
    {
        try {
            // Get disk usage
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

            return [
                'mounts' => $mounts
            ];

        } catch (\Exception $e) {
            return ['mounts' => []];
        }
    }

    /**
     * Create DatabaseInfo from server capabilities
     *
     * @throws PhpBorgException
     * @return int DatabaseInfo ID
     */
    private function createDatabaseInfo($server, string $backupType, array $sourceConfig, string $repoId): int
    {
        error_log("=== createDatabaseInfo DEBUG ===");
        error_log("backupType: $backupType");
        error_log("sourceConfig: " . json_encode($sourceConfig));
        error_log("repoId: $repoId");

        // Map backup type to database type in capabilities
        $dbTypeMap = [
            'mysql' => 'mysql',
            'mariadb' => 'mysql',
            'postgresql' => 'postgresql',
            'postgres' => 'postgresql',
            'mongodb' => 'mongodb',
            'docker' => 'docker'
        ];

        $dbType = $dbTypeMap[$backupType] ?? $backupType;
        error_log("Mapped dbType: $dbType");

        // Special case: Docker doesn't need snapshot validation
        if ($dbType === 'docker') {
            return $this->createDockerDatabaseInfo($server, $sourceConfig, $repoId);
        }

        // Get server capabilities
        if (!$server->capabilitiesDetected || !$server->capabilitiesData) {
            throw new PhpBorgException(
                "Server capabilities not detected. Please run capability detection first.",
                400
            );
        }

        $capabilities = json_decode($server->capabilitiesData, true);
        if (!$capabilities || !isset($capabilities['databases'])) {
            throw new PhpBorgException("Invalid capabilities data", 500);
        }

        // Find the matching database in capabilities
        $database = null;
        foreach ($capabilities['databases'] as $db) {
            if ($db['type'] === $dbType) {
                $database = $db;
                break;
            }
        }

        if (!$database) {
            throw new PhpBorgException(
                "Database type '{$dbType}' not found in server capabilities",
                400
            );
        }

        // Verify database is snapshot-capable
        error_log("Checking snapshot_capable: " . ($database['snapshot_capable'] ?? 'NOT SET'));
        if (!($database['snapshot_capable'] ?? false)) {
            throw new PhpBorgException(
                "Database '{$dbType}' is not on a snapshot-capable volume (LVM/Btrfs/ZFS required)",
                400
            );
        }

        // Verify we have required LVM information
        error_log("Checking LVM info - vg_name: " . ($database['volume']['vg_name'] ?? 'NOT SET') . ", lv_name: " . ($database['volume']['lv_name'] ?? 'NOT SET'));
        if (!isset($database['volume']['vg_name']) || !isset($database['volume']['lv_name'])) {
            throw new PhpBorgException(
                "LVM volume information not found in capabilities for '{$dbType}'",
                400
            );
        }

        error_log("Checking datadir: " . ($database['datadir'] ?? 'NOT SET'));
        if (!isset($database['datadir'])) {
            throw new PhpBorgException(
                "Database datadir not found in capabilities for '{$dbType}'",
                400
            );
        }

        // Extract LVM information from capabilities
        $vgName = $database['volume']['vg_name'];
        $lvName = $database['volume']['lv_name'];
        // Use LVM format (no space) or fallback to removing space from human format
        $lvSize = $database['snapshot_size']['recommended_lvm']
            ?? str_replace(' ', '', $database['snapshot_size']['recommended_size'] ?? '10G');
        $datadir = $database['datadir'];

        error_log("Extracted - vgName: $vgName, lvName: $lvName, lvSize: $lvSize, datadir: $datadir");

        // Extract database credentials from source config
        $dbHost = $sourceConfig['host'] ?? 'localhost';
        $dbUser = $sourceConfig['username'] ?? '';
        $dbPassword = $sourceConfig['password'] ?? '';

        error_log("Credentials - dbHost: $dbHost, dbUser: $dbUser, dbPassword: " . (empty($dbPassword) ? 'EMPTY' : 'SET'));

        // Validate required credentials
        if (empty($dbUser)) {
            error_log("ERROR: dbUser is empty!");
            throw new PhpBorgException(
                "Database username is required in source configuration",
                400
            );
        }

        error_log("Creating DatabaseInfo in DB...");

        // Create DatabaseInfo
        $dbInfoId = $this->databaseInfoRepository->create(
            type: $dbType,
            serverId: $server->id,
            dbHost: $dbHost,
            dbUser: $dbUser,
            dbPassword: $dbPassword,
            vgName: $vgName,
            lvmPartition: $lvName,
            lvSize: $lvSize,
            dataPath: $datadir,
            repoId: $repoId
        );

        error_log("DatabaseInfo created successfully with ID: $dbInfoId");
        return $dbInfoId;
    }

    /**
     * Create DatabaseInfo for Docker backups
     * Docker doesn't use LVM snapshots, so we store source config as JSON
     *
     * @throws PhpBorgException
     * @return int DatabaseInfo ID
     */
    private function createDockerDatabaseInfo($server, array $sourceConfig, string $repoId): int
    {
        error_log("=== createDockerDatabaseInfo DEBUG ===");
        error_log("sourceConfig: " . json_encode($sourceConfig));
        error_log("repoId: $repoId");

        // Store source config as JSON in mysqlPath field (reusing field for simplicity)
        // This includes: backupAllVolumes, selectedVolumes, selectedComposeProjects, backupDockerConfig, backupCustomNetworks
        $sourceConfigJson = json_encode($sourceConfig);

        // Create DatabaseInfo with minimal fields
        // Docker doesn't use LVM snapshots, so vg_name, lv_name, lv_size are set to dummy values
        $dbInfoId = $this->databaseInfoRepository->create(
            type: 'docker',
            serverId: $server->id,
            dbHost: 'localhost',
            dbUser: 'docker',
            dbPassword: '',
            vgName: 'none',
            lvmPartition: 'none',
            lvSize: '0',
            dataPath: $sourceConfigJson,  // Store source config in dataPath field
            repoId: $repoId
        );

        error_log("Docker DatabaseInfo created successfully with ID: $dbInfoId");
        return $dbInfoId;
    }
}