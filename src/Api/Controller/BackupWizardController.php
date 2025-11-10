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
use PhpBorg\Exception\PhpBorgException;
use PhpBorg\Service\Server\SshExecutor;
use PhpBorg\Logger\ConsoleLogger;

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
    private readonly SshExecutor $sshExecutor;

    public function __construct(Application $app)
    {
        $this->serverRepository = new ServerRepository($app->getConnection());
        $this->storagePoolRepository = new StoragePoolRepository($app->getConnection());
        $this->repositoryRepository = new BorgRepositoryRepository($app->getConnection());
        $this->sourceRepository = new BackupSourceRepository($app->getConnection());
        $this->jobRepository = new BackupJobRepository($app->getConnection());
        $this->scheduleRepository = new BackupScheduleRepository($app->getConnection());
        $this->sshExecutor = new SshExecutor(new ConsoleLogger());
    }

    /**
     * Detect server capabilities (snapshot methods, databases, etc.)
     * GET /api/backup-wizard/capabilities/{serverId}
     */
    public function capabilities(int $serverId): array
    {
        $server = $this->serverRepository->findById($serverId);
        if (!$server) {
            throw new PhpBorgException('Server not found', 404);
        }

        $capabilities = [
            'snapshots' => $this->detectSnapshotCapabilities($server),
            'databases' => $this->detectDatabases($server),
            'filesystem' => $this->getFilesystemInfo($server)
        ];

        return [
            'success' => true,
            'data' => $capabilities
        ];
    }

    /**
     * Auto-detect MySQL credentials
     * POST /api/backup-wizard/detect-mysql
     */
    public function detectMySQL(array $data): array
    {
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
                    return [
                        'success' => true,
                        'data' => [
                            'detected' => true,
                            'source' => '/etc/mysql/debian.cnf',
                            'username' => trim($userMatch[1]),
                            'password' => trim($passMatch[1]),
                            'host' => 'localhost',
                            'port' => 3306
                        ]
                    ];
                }
            }
            
            // Try .my.cnf in home directory
            $result = $this->sshExecutor->execute($server, 'cat ~/.my.cnf 2>/dev/null', 10);
            $myCnf = $result['stdout'];
            if ($myCnf && strpos($myCnf, 'user') !== false) {
                preg_match('/user\s*=\s*(.+)/', $myCnf, $userMatch);
                preg_match('/password\s*=\s*(.+)/', $myCnf, $passMatch);
                
                if ($userMatch) {
                    return [
                        'success' => true,
                        'data' => [
                            'detected' => true,
                            'source' => '~/.my.cnf',
                            'username' => trim($userMatch[1]),
                            'password' => isset($passMatch[1]) ? trim($passMatch[1]) : '',
                            'host' => 'localhost',
                            'port' => 3306
                        ]
                    ];
                }
            }
            
        } catch (\Exception $e) {
            // SSH connection failed
        }

        return [
            'success' => true,
            'data' => [
                'detected' => false,
                'message' => 'Could not auto-detect MySQL credentials'
            ]
        ];
    }

    /**
     * Test database connection
     * POST /api/backup-wizard/test-db-connection
     */
    public function testDatabaseConnection(array $data): array
    {
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
                
                return [
                    'success' => true,
                    'data' => [
                        'connected' => $success,
                        'message' => $success ? 'Connection successful' : 'Connection failed: ' . $result
                    ]
                ];
            }
            
            // Add other database types as needed
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Create complete backup configuration
     * POST /api/backup-wizard/create-backup-chain
     */
    public function createBackupChain(array $data): array
    {
        $connection = $this->repositoryRepository->getConnection();
        
        try {
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
            $repoPath = sprintf(
                '/backup/borg/%s-%s-%d',
                $data['repository_name'],
                $data['backup_type'],
                time()
            );
            
            $repositoryId = $this->repositoryRepository->create(
                serverId: (int)$data['server_id'],
                repoId: uniqid('repo_'),
                type: $data['backup_type'],
                encryption: $data['encryption'] ?? 'repokey-blake2',
                passphrase: $data['passphrase'] ?? '',
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
            
            // 3. Create backup job
            $jobId = $this->jobRepository->create(
                name: $data['job_name'] ?? $data['repository_name'],
                repositoryId: $repositoryId,
                scheduleType: $data['schedule_type'] ?? 'manual',
                scheduleTime: $data['schedule_time'] ?? null,
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
            
            return [
                'success' => true,
                'message' => 'Backup configuration created successfully',
                'data' => [
                    'source_id' => $source->id,
                    'repository_id' => $repositoryId,
                    'job_id' => $jobId
                ]
            ];
            
        } catch (\Exception $e) {
            $connection->rollBack();
            throw new PhpBorgException('Failed to create backup configuration: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get backup templates
     * GET /api/backup-wizard/templates
     */
    public function templates(): array
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
        
        return [
            'success' => true,
            'data' => $templates
        ];
    }

    /**
     * Detect snapshot capabilities on server
     */
    private function detectSnapshotCapabilities($server): array
    {
        $capabilities = [];
        
        try {
            // Check LVM
            $result = $this->sshExecutor->execute($server, 'which lvcreate 2>/dev/null', 5);
            if ($result['stdout']) {
                $result = $this->sshExecutor->execute($server, 'sudo lvs --noheadings -o lv_name,vg_name,lv_path 2>/dev/null', 10);
                $lvs = $result['stdout'];
                if ($lvs) {
                    $capabilities[] = [
                        'type' => 'lvm',
                        'name' => 'LVM Snapshot',
                        'available' => true,
                        'description' => 'Logical Volume Manager snapshots for consistent backups',
                        'details' => count(explode("\n", trim($lvs))) . ' volumes available'
                    ];
                }
            }
            
            // Check ZFS
            $result = $this->sshExecutor->execute($server, 'which zfs 2>/dev/null', 5);
            if ($result['stdout']) {
                $result = $this->sshExecutor->execute($server, 'zfs list -H -o name 2>/dev/null', 10);
                $datasets = $result['stdout'];
                if ($datasets) {
                    $capabilities[] = [
                        'type' => 'zfs',
                        'name' => 'ZFS Snapshot',
                        'available' => true,
                        'description' => 'ZFS dataset snapshots with instant creation',
                        'details' => count(explode("\n", trim($datasets))) . ' datasets available'
                    ];
                }
            }
            
            // Check Btrfs
            $result = $this->sshExecutor->execute($server, 'which btrfs 2>/dev/null', 5);
            if ($result['stdout']) {
                $result = $this->sshExecutor->execute($server, 'sudo btrfs subvolume list / 2>/dev/null', 10);
                $subvolumes = $result['stdout'];
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
            // SSH connection failed, return empty capabilities
        }
        
        return $capabilities;
    }

    /**
     * Detect databases on server
     */
    private function detectDatabases($server): array
    {
        $databases = [];
        
        try {
            // Check MySQL/MariaDB
            $result = $this->sshExecutor->execute($server, 'which mysql 2>/dev/null', 5);
            if ($result['stdout']) {
                $result = $this->sshExecutor->execute($server, 'mysql --version 2>/dev/null | head -n1', 5);
                $databases[] = [
                    'type' => 'mysql',
                    'name' => 'MySQL/MariaDB',
                    'detected' => true,
                    'version' => trim($result['stdout'])
                ];
            }
            
            // Check PostgreSQL
            $result = $this->sshExecutor->execute($server, 'which psql 2>/dev/null', 5);
            if ($result['stdout']) {
                $result = $this->sshExecutor->execute($server, 'psql --version 2>/dev/null | head -n1', 5);
                $databases[] = [
                    'type' => 'postgresql',
                    'name' => 'PostgreSQL',
                    'detected' => true,
                    'version' => trim($result['stdout'])
                ];
            }
            
            // Check MongoDB
            $result = $this->sshExecutor->execute($server, 'which mongod 2>/dev/null', 5);
            if ($result['stdout']) {
                $result = $this->sshExecutor->execute($server, 'mongod --version 2>/dev/null | head -n1', 5);
                $databases[] = [
                    'type' => 'mongodb',
                    'name' => 'MongoDB',
                    'detected' => true,
                    'version' => trim($result['stdout'])
                ];
            }
            
        } catch (\Exception $e) {
            // SSH connection failed
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
}