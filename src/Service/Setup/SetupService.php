<?php

declare(strict_types=1);

namespace PhpBorg\Service\Setup;

use PhpBorg\Config\Configuration;
use PhpBorg\Database\Connection;
use PhpBorg\Exception\PhpBorgException;
use PhpBorg\Logger\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Setup and installation verification service
 */
final class SetupService
{
    /** @var array<string, array{status: string, message: string, required: bool}> */
    private array $checks = [];

    public function __construct(
        private readonly ?Configuration $config = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Run all checks
     *
     * @return array<string, array{status: string, message: string, required: bool}>
     */
    public function runAllChecks(): array
    {
        $this->checks = [];

        // PHP checks
        $this->checkPhpVersion();
        $this->checkPhpExtensions();

        // Environment checks
        $this->checkEnvFile();

        // Only run these if .env exists
        if (file_exists(dirname(__DIR__, 3) . '/.env')) {
            $this->checkDatabase();
            $this->checkBorgBackup();
            $this->checkDirectories();
            $this->checkPermissions();
            $this->checkSshAccess();
        }

        return $this->checks;
    }

    /**
     * Check if all required checks passed
     */
    public function allRequiredChecksPassed(): bool
    {
        foreach ($this->checks as $check) {
            if ($check['required'] && $check['status'] !== 'ok') {
                return false;
            }
        }
        return true;
    }

    /**
     * Get failed checks
     *
     * @return array<string, array{status: string, message: string, required: bool}>
     */
    public function getFailedChecks(): array
    {
        return array_filter($this->checks, fn($check) => $check['status'] === 'error');
    }

    /**
     * Check PHP version
     */
    private function checkPhpVersion(): void
    {
        if (PHP_VERSION_ID >= 80300) {
            $this->checks['php_version'] = [
                'status' => 'ok',
                'message' => 'PHP ' . PHP_VERSION . ' (>= 8.3.0 required)',
                'required' => true,
            ];
        } else {
            $this->checks['php_version'] = [
                'status' => 'error',
                'message' => 'PHP ' . PHP_VERSION . ' is too old. PHP 8.3.0+ required',
                'required' => true,
            ];
        }
    }

    /**
     * Check required PHP extensions
     */
    private function checkPhpExtensions(): void
    {
        $required = ['mysqli', 'openssl', 'posix', 'json', 'curl'];
        $missing = [];

        foreach ($required as $ext) {
            if (!extension_loaded($ext)) {
                $missing[] = $ext;
            }
        }

        if (empty($missing)) {
            $this->checks['php_extensions'] = [
                'status' => 'ok',
                'message' => 'All required extensions loaded: ' . implode(', ', $required),
                'required' => true,
            ];
        } else {
            $this->checks['php_extensions'] = [
                'status' => 'error',
                'message' => 'Missing extensions: ' . implode(', ', $missing),
                'required' => true,
            ];
        }
    }

    /**
     * Check .env file
     */
    private function checkEnvFile(): void
    {
        $envPath = dirname(__DIR__, 3) . '/.env';
        $envExamplePath = dirname(__DIR__, 3) . '/.env.example';

        if (file_exists($envPath)) {
            $this->checks['env_file'] = [
                'status' => 'ok',
                'message' => '.env file exists',
                'required' => true,
            ];
        } elseif (file_exists($envExamplePath)) {
            $this->checks['env_file'] = [
                'status' => 'warning',
                'message' => '.env file not found. Run: cp .env.example .env',
                'required' => true,
            ];
        } else {
            $this->checks['env_file'] = [
                'status' => 'error',
                'message' => 'Neither .env nor .env.example found',
                'required' => true,
            ];
        }
    }

    /**
     * Check database connection
     */
    private function checkDatabase(): void
    {
        if ($this->config === null) {
            $this->checks['database'] = [
                'status' => 'warning',
                'message' => 'Cannot check database (config not loaded)',
                'required' => true,
            ];
            return;
        }

        try {
            $connection = new Connection(
                $this->config->dbHost,
                $this->config->dbPort,
                $this->config->dbName,
                $this->config->dbUser,
                $this->config->dbPassword,
                $this->config->dbCharset
            );

            // Try to execute a simple query
            $result = $connection->fetchOne('SELECT 1 as test');

            if ($result && $result['test'] === 1) {
                // Check if tables exist
                $tables = $connection->fetchAll("SHOW TABLES");
                $tableCount = count($tables);

                if ($tableCount >= 5) {
                    $this->checks['database'] = [
                        'status' => 'ok',
                        'message' => "Database connected ({$tableCount} tables found)",
                        'required' => true,
                    ];
                } else {
                    $this->checks['database'] = [
                        'status' => 'warning',
                        'message' => "Database connected but tables missing. Run: mysql -u {$this->config->dbUser} -p {$this->config->dbName} < backup.sql",
                        'required' => true,
                    ];
                }
            }

            $connection->close();
        } catch (\Exception $e) {
            $this->checks['database'] = [
                'status' => 'error',
                'message' => 'Database connection failed: ' . $e->getMessage(),
                'required' => true,
            ];
        }
    }

    /**
     * Check BorgBackup installation
     */
    private function checkBorgBackup(): void
    {
        $borgPath = $this->config?->borgBinaryPath ?? '/usr/bin/borg';

        // Check if borg exists
        if (!file_exists($borgPath)) {
            $this->checks['borg_binary'] = [
                'status' => 'error',
                'message' => "BorgBackup not found at {$borgPath}. Install with: apt-get install borgbackup",
                'required' => true,
            ];
            return;
        }

        // Check if executable
        if (!is_executable($borgPath)) {
            $this->checks['borg_binary'] = [
                'status' => 'error',
                'message' => "BorgBackup found but not executable at {$borgPath}",
                'required' => true,
            ];
            return;
        }

        // Check version
        $process = new Process([$borgPath, '--version']);
        $process->run();

        if ($process->isSuccessful()) {
            $version = trim($process->getOutput());
            $this->checks['borg_binary'] = [
                'status' => 'ok',
                'message' => "BorgBackup installed: {$version}",
                'required' => true,
            ];
        } else {
            $this->checks['borg_binary'] = [
                'status' => 'error',
                'message' => 'BorgBackup found but failed to run',
                'required' => true,
            ];
        }
    }

    /**
     * Check required directories
     */
    private function checkDirectories(): void
    {
        if ($this->config === null) {
            return;
        }

        $directories = [
            'backup_path' => $this->config->borgBackupPath,
            'log_dir' => dirname($this->config->logPath),
        ];

        $missing = [];
        $notWritable = [];

        foreach ($directories as $name => $path) {
            if (!is_dir($path)) {
                $missing[] = "{$name}: {$path}";
            } elseif (!is_writable($path)) {
                $notWritable[] = "{$name}: {$path}";
            }
        }

        if (empty($missing) && empty($notWritable)) {
            $this->checks['directories'] = [
                'status' => 'ok',
                'message' => 'All required directories exist and are writable',
                'required' => true,
            ];
        } elseif (!empty($missing)) {
            $this->checks['directories'] = [
                'status' => 'error',
                'message' => 'Missing directories: ' . implode(', ', $missing),
                'required' => true,
            ];
        } else {
            $this->checks['directories'] = [
                'status' => 'error',
                'message' => 'Not writable: ' . implode(', ', $notWritable),
                'required' => true,
            ];
        }
    }

    /**
     * Check permissions
     */
    private function checkPermissions(): void
    {
        if ($this->config === null) {
            return;
        }

        $logPath = $this->config->logPath;

        if (file_exists($logPath)) {
            if (is_writable($logPath)) {
                $this->checks['log_permissions'] = [
                    'status' => 'ok',
                    'message' => 'Log file is writable',
                    'required' => true,
                ];
            } else {
                $this->checks['log_permissions'] = [
                    'status' => 'error',
                    'message' => "Log file not writable: {$logPath}",
                    'required' => true,
                ];
            }
        } else {
            // Try to create log file
            $logDir = dirname($logPath);
            if (is_writable($logDir)) {
                $this->checks['log_permissions'] = [
                    'status' => 'ok',
                    'message' => 'Log directory is writable (log file will be created)',
                    'required' => true,
                ];
            } else {
                $this->checks['log_permissions'] = [
                    'status' => 'error',
                    'message' => "Log directory not writable: {$logDir}",
                    'required' => true,
                ];
            }
        }
    }

    /**
     * Check SSH access
     */
    private function checkSshAccess(): void
    {
        // Check if ssh command exists
        $process = new Process(['which', 'ssh']);
        $process->run();

        if ($process->isSuccessful()) {
            $sshPath = trim($process->getOutput());
            $this->checks['ssh'] = [
                'status' => 'ok',
                'message' => "SSH client available: {$sshPath}",
                'required' => true,
            ];
        } else {
            $this->checks['ssh'] = [
                'status' => 'error',
                'message' => 'SSH client not found. Install with: apt-get install openssh-client',
                'required' => true,
            ];
        }
    }

    /**
     * Install BorgBackup
     */
    public function installBorgBackup(): bool
    {
        // Try apt-get first
        $process = new Process(['which', 'apt-get']);
        $process->run();

        if ($process->isSuccessful()) {
            $this->logger?->info('Installing BorgBackup via apt-get...', 'SETUP');

            $install = new Process(['sudo', 'apt-get', 'install', '-y', 'borgbackup'], null, null, null, 300);
            $install->run();

            return $install->isSuccessful();
        }

        // Try yum
        $process = new Process(['which', 'yum']);
        $process->run();

        if ($process->isSuccessful()) {
            $this->logger?->info('Installing BorgBackup via yum...', 'SETUP');

            $install = new Process(['sudo', 'yum', 'install', '-y', 'borgbackup'], null, null, null, 300);
            $install->run();

            return $install->isSuccessful();
        }

        // Try dnf
        $process = new Process(['which', 'dnf']);
        $process->run();

        if ($process->isSuccessful()) {
            $this->logger?->info('Installing BorgBackup via dnf...', 'SETUP');

            $install = new Process(['sudo', 'dnf', 'install', '-y', 'borgbackup'], null, null, null, 300);
            $install->run();

            return $install->isSuccessful();
        }

        return false;
    }

    /**
     * Create required directories
     */
    public function createDirectories(): bool
    {
        if ($this->config === null) {
            return false;
        }

        try {
            $directories = [
                $this->config->borgBackupPath,
                dirname($this->config->logPath),
            ];

            foreach ($directories as $dir) {
                if (!is_dir($dir)) {
                    if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                        $this->logger?->error("Failed to create directory: {$dir}", 'SETUP');
                        return false;
                    }
                    $this->logger?->info("Created directory: {$dir}", 'SETUP');
                }
            }

            return true;
        } catch (\Exception $e) {
            $this->logger?->error("Error creating directories: {$e->getMessage()}", 'SETUP');
            return false;
        }
    }

    /**
     * Create .env file from example
     */
    public function createEnvFile(): bool
    {
        $envPath = dirname(__DIR__, 3) . '/.env';
        $envExamplePath = dirname(__DIR__, 3) . '/.env.example';

        if (file_exists($envPath)) {
            return true; // Already exists
        }

        if (!file_exists($envExamplePath)) {
            return false;
        }

        return copy($envExamplePath, $envPath);
    }
}
