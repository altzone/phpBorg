<?php

declare(strict_types=1);

namespace PhpBorg\Service\Queue\Handlers;

use DateTimeImmutable;
use PhpBorg\Config\Configuration;
use PhpBorg\Database\Connection;
use PhpBorg\Entity\Job;
use PhpBorg\Exception\BackupException;
use PhpBorg\Logger\LoggerInterface;
use PhpBorg\Repository\PhpBorgBackupRepository;
use PhpBorg\Repository\SettingRepository;
use PhpBorg\Service\Queue\JobQueue;

/**
 * Handler for creating complete phpBorg self-backups
 *
 * Backup includes:
 * - Complete codebase (excluding node_modules, vendor, .git)
 * - Complete database dump
 * - Configuration files (.env)
 * - SSH keys (/root/.ssh/phpborg_backup*)
 * - Systemd services (/etc/systemd/system/phpborg-*)
 *
 * Features:
 * - Optional AES-256-CBC encryption with PBKDF2
 * - SHA256 hash verification
 * - Atomic creation (tmp file + rename)
 * - Auto-cleanup of old backups
 * - Worker pool management (stops workers #2-#4 during backup)
 */
final class PhpBorgBackupCreateHandler implements JobHandlerInterface
{
    public function __construct(
        private readonly Configuration $config,
        private readonly Connection $connection,
        private readonly PhpBorgBackupRepository $backupRepository,
        private readonly SettingRepository $settingRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    public function handle(Job $job, JobQueue $queue): string
    {
        $payload = $job->payload;
        $backupType = $payload['backup_type'] ?? 'manual';
        $userId = $payload['user_id'] ?? null;
        $notes = $payload['notes'] ?? null;

        $this->logger->info("Starting phpBorg backup creation", 'PHPBORG_BACKUP', [
            'type' => $backupType,
            'user_id' => $userId
        ]);

        try {
            // Step 1: Stop workers #2, #3, #4 (keep #1 active)
            $this->logger->info("Stopping background workers...");
            $this->stopWorkers();

            // Step 2: Load settings
            $settings = $this->loadBackupSettings();
            $this->logger->info("Backup settings loaded", 'PHPBORG_BACKUP', [
                'storage_path' => $settings['storage_path'],
                'encryption' => $settings['encryption_enabled'] ? 'enabled' : 'disabled'
            ]);

            // Step 3: Create backup directory if not exists
            $this->ensureBackupDirectory($settings['storage_path']);

            // Step 4: Generate backup filename
            $timestamp = (new DateTimeImmutable())->format('Y-m-d_H-i-s');
            $filename = sprintf('phpborg_backup_%s_%s.tar.gz', $backupType, $timestamp);
            $tmpPath = $settings['storage_path'] . '/' . $filename . '.tmp';
            $finalPath = $settings['storage_path'] . '/' . $filename;

            // Step 5: Create temporary working directory
            $workDir = '/tmp/phpborg_backup_' . uniqid();
            $this->createWorkingDirectory($workDir);

            try {
                // Step 6: Dump database
                $this->logger->info("Dumping database...");
                $dbDumpPath = $workDir . '/database.sql';
                $this->dumpDatabase($dbDumpPath);

                // Step 7: Collect version info
                $this->logger->info("Collecting version information...");
                $versions = $this->collectVersions();

                // Step 8: Create metadata.json
                $metadata = $this->createMetadata($backupType, $userId, $notes, $versions, $settings['encryption_enabled']);
                file_put_contents($workDir . '/metadata.json', json_encode($metadata, JSON_PRETTY_PRINT));

                // Step 9: Create tar.gz archive
                $this->logger->info("Creating tar.gz archive...");
                $this->createTarArchive($workDir, $tmpPath);

                // Step 10: Calculate SHA256 hash
                $this->logger->info("Calculating SHA256 hash...");
                $hash = hash_file('sha256', $tmpPath);

                // Step 11: Encrypt if enabled
                if ($settings['encryption_enabled']) {
                    $this->logger->info("Encrypting backup...");
                    $encryptedPath = $tmpPath . '.enc';
                    $this->encryptBackup($tmpPath, $encryptedPath, $settings['encryption_passphrase']);
                    unlink($tmpPath);
                    rename($encryptedPath, $finalPath . '.enc');
                    $finalPath .= '.enc';
                    $filename .= '.enc';
                } else {
                    rename($tmpPath, $finalPath);
                }

                // Step 12: Get file size
                $sizeBytes = filesize($finalPath);

                // Step 13: Save to database
                $this->logger->info("Saving backup record to database...");
                $backupId = $this->backupRepository->create(
                    filename: $filename,
                    filepath: $finalPath,
                    sizeBytes: $sizeBytes,
                    encrypted: $settings['encryption_enabled'],
                    hashSha256: $hash,
                    phpborgVersion: $versions['phpborg_version'],
                    phpVersion: $versions['php_version'],
                    mysqlVersion: $versions['mysql_version'],
                    nodeVersion: $versions['node_version'],
                    borgVersion: $versions['borg_version'],
                    createdBy: $userId,
                    backupType: $backupType,
                    notes: $notes
                );

                // Step 14: Auto-cleanup old backups
                $this->logger->info("Checking for old backups to cleanup...");
                $this->cleanupOldBackups($settings['retention_count']);

                // Step 15: Cleanup working directory
                $this->cleanupWorkingDirectory($workDir);

                $message = sprintf(
                    "Backup created successfully: %s (%.2f MB, %s)",
                    $filename,
                    $sizeBytes / 1024 / 1024,
                    $settings['encryption_enabled'] ? 'encrypted' : 'unencrypted'
                );

                $this->logger->info($message, 'PHPBORG_BACKUP', ['backup_id' => $backupId]);

                return $message;

            } finally {
                // Always cleanup working directory
                if (is_dir($workDir)) {
                    $this->cleanupWorkingDirectory($workDir);
                }
            }

        } finally {
            // Always restart workers
            $this->logger->info("Restarting background workers...");
            $this->startWorkers();
        }
    }

    /**
     * Stop workers #2, #3, #4 (keep #1 active)
     */
    private function stopWorkers(): void
    {
        foreach ([2, 3, 4] as $workerNum) {
            $cmd = sprintf('sudo systemctl stop phpborg-worker@%d 2>&1', $workerNum);
            $output = [];
            $exitCode = 0;
            exec($cmd, $output, $exitCode);

            if ($exitCode !== 0) {
                $this->logger->warning("Failed to stop worker #{$workerNum}", [
                    'output' => implode("\n", $output)
                ]);
            }
        }

        // Wait for workers to stop
        sleep(2);
    }

    /**
     * Start workers #2, #3, #4
     */
    private function startWorkers(): void
    {
        foreach ([2, 3, 4] as $workerNum) {
            $cmd = sprintf('sudo systemctl start phpborg-worker@%d 2>&1', $workerNum);
            $output = [];
            $exitCode = 0;
            exec($cmd, $output, $exitCode);

            if ($exitCode !== 0) {
                $this->logger->warning("Failed to start worker #{$workerNum}", [
                    'output' => implode("\n", $output)
                ]);
            }
        }
    }

    /**
     * Load backup settings from database
     *
     * @return array{storage_path: string, encryption_enabled: bool, encryption_passphrase: string, retention_count: int}
     */
    private function loadBackupSettings(): array
    {
        $storagePath = $this->settingRepository->get('backup_storage_path', '/opt/backups/phpborg-self-backups');
        $encryptionEnabled = $this->settingRepository->get('backup_encryption_enabled', '0') === '1';
        $encryptionPassphrase = $this->settingRepository->get('backup_encryption_passphrase', '');
        $retentionCount = (int)$this->settingRepository->get('backup_retention_count', '3');

        if ($encryptionEnabled && empty($encryptionPassphrase)) {
            throw new BackupException("Encryption is enabled but passphrase is not set");
        }

        return [
            'storage_path' => $storagePath,
            'encryption_enabled' => $encryptionEnabled,
            'encryption_passphrase' => $encryptionPassphrase,
            'retention_count' => $retentionCount
        ];
    }

    /**
     * Ensure backup directory exists with correct permissions
     */
    private function ensureBackupDirectory(string $path): void
    {
        if (!is_dir($path)) {
            if (!mkdir($path, 0755, true)) {
                throw new BackupException("Failed to create backup directory: {$path}");
            }
            $this->logger->info("Created backup directory: {$path}");
        }

        if (!is_writable($path)) {
            throw new BackupException("Backup directory is not writable: {$path}");
        }
    }

    /**
     * Create temporary working directory
     */
    private function createWorkingDirectory(string $path): void
    {
        if (!mkdir($path, 0700)) {
            throw new BackupException("Failed to create working directory: {$path}");
        }
    }

    /**
     * Dump database to SQL file
     */
    private function dumpDatabase(string $outputPath): void
    {
        $cmd = sprintf(
            'mysqldump --host=%s --port=%d --user=%s --password=%s --single-transaction --routines --triggers %s > %s 2>&1',
            escapeshellarg($this->config->dbHost),
            $this->config->dbPort,
            escapeshellarg($this->config->dbUser),
            escapeshellarg($this->config->dbPassword),
            escapeshellarg($this->config->dbName),
            escapeshellarg($outputPath)
        );

        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0 || !file_exists($outputPath) || filesize($outputPath) === 0) {
            throw new BackupException("Database dump failed: " . implode("\n", $output));
        }

        $this->logger->info("Database dumped successfully", 'PHPBORG_BACKUP', [
            'size' => filesize($outputPath)
        ]);
    }

    /**
     * Collect version information
     *
     * @return array{phpborg_version: string, php_version: string, mysql_version: string|null, node_version: string|null, borg_version: string|null}
     */
    private function collectVersions(): array
    {
        // phpBorg version (from git or VERSION file)
        $phpborgVersion = 'unknown';
        $gitVersion = shell_exec('cd ' . escapeshellarg(dirname(__DIR__, 4)) . ' && git describe --tags --always 2>/dev/null');
        if ($gitVersion) {
            $phpborgVersion = trim($gitVersion);
        }

        // PHP version
        $phpVersion = PHP_VERSION;

        // MySQL/MariaDB version
        $mysqlVersion = null;
        try {
            $result = $this->connection->fetchOne('SELECT VERSION() as version');
            $mysqlVersion = $result['version'] ?? null;
        } catch (\Exception $e) {
            $this->logger->warning("Could not get MySQL version: " . $e->getMessage());
        }

        // Node.js version
        $nodeVersion = null;
        $nodeOutput = shell_exec('node --version 2>/dev/null');
        if ($nodeOutput) {
            $nodeVersion = trim($nodeOutput);
        }

        // BorgBackup version
        $borgVersion = null;
        $borgOutput = shell_exec('borg --version 2>/dev/null');
        if ($borgOutput) {
            $borgVersion = trim($borgOutput);
        }

        return [
            'phpborg_version' => $phpborgVersion,
            'php_version' => $phpVersion,
            'mysql_version' => $mysqlVersion,
            'node_version' => $nodeVersion,
            'borg_version' => $borgVersion
        ];
    }

    /**
     * Create metadata.json content
     *
     * @param array{phpborg_version: string, php_version: string, mysql_version: string|null, node_version: string|null, borg_version: string|null} $versions
     */
    private function createMetadata(string $backupType, ?int $userId, ?string $notes, array $versions, bool $encrypted): array
    {
        return [
            'backup_type' => $backupType,
            'created_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            'created_by' => $userId,
            'notes' => $notes,
            'encrypted' => $encrypted,
            'versions' => $versions,
            'structure' => [
                'phpborg/' => 'Complete phpBorg codebase',
                'database.sql' => 'Complete database dump',
                '.env' => 'Environment configuration',
                'ssh_keys/' => 'SSH keys for backup operations',
                'systemd/' => 'Systemd service files'
            ]
        ];
    }

    /**
     * Create tar.gz archive with all backup contents
     */
    private function createTarArchive(string $workDir, string $outputPath): void
    {
        $phpborgRoot = dirname(__DIR__, 4);

        // Build tar command
        $cmd = sprintf(
            'cd %s && tar -czf %s ' .
            '--exclude=node_modules ' .
            '--exclude=vendor ' .
            '--exclude=.git ' .
            '--exclude=frontend/node_modules ' .
            '--exclude=frontend/dist ' .
            '--exclude=tmp ' .
            '-C %s . ' .
            '-C %s database.sql metadata.json ' .
            '-C /root/.ssh phpborg_backup* ' .
            '-C /etc/systemd/system phpborg-* ' .
            '2>&1',
            escapeshellarg($phpborgRoot),
            escapeshellarg($outputPath),
            escapeshellarg($phpborgRoot),
            escapeshellarg($workDir)
        );

        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0 || !file_exists($outputPath)) {
            throw new BackupException("Failed to create tar archive: " . implode("\n", $output));
        }

        $this->logger->info("Tar archive created successfully", 'PHPBORG_BACKUP', [
            'size' => filesize($outputPath)
        ]);
    }

    /**
     * Encrypt backup file with AES-256-CBC
     */
    private function encryptBackup(string $inputPath, string $outputPath, string $passphrase): void
    {
        // Use OpenSSL with PBKDF2 (100k iterations)
        $cmd = sprintf(
            'openssl enc -aes-256-cbc -salt -pbkdf2 -iter 100000 -in %s -out %s -pass pass:%s 2>&1',
            escapeshellarg($inputPath),
            escapeshellarg($outputPath),
            escapeshellarg($passphrase)
        );

        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0 || !file_exists($outputPath)) {
            throw new BackupException("Encryption failed: " . implode("\n", $output));
        }

        $this->logger->info("Backup encrypted successfully");
    }

    /**
     * Cleanup old backups (keep only N most recent)
     */
    private function cleanupOldBackups(int $keepCount): void
    {
        $oldBackups = $this->backupRepository->findOldestForCleanup($keepCount);

        if (empty($oldBackups)) {
            $this->logger->info("No old backups to cleanup");
            return;
        }

        foreach ($oldBackups as $backup) {
            try {
                // Delete file
                if (file_exists($backup->filepath)) {
                    unlink($backup->filepath);
                    $this->logger->info("Deleted old backup file: {$backup->filename}");
                }

                // Delete database record
                $this->backupRepository->delete($backup->id);
                $this->logger->info("Deleted old backup record: {$backup->filename}");

            } catch (\Exception $e) {
                $this->logger->error("Failed to delete old backup: {$backup->filename}", [
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Cleanup working directory
     */
    private function cleanupWorkingDirectory(string $path): void
    {
        $cmd = sprintf('rm -rf %s 2>&1', escapeshellarg($path));
        exec($cmd);
    }
}
