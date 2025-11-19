<?php

declare(strict_types=1);

namespace PhpBorg\Logger;

use PhpBorg\Repository\SettingRepository;

/**
 * Logger for user-initiated operations (backups, restores, etc.)
 * Separate from engine/infrastructure logs
 */
final class UserOperationLogger
{
    private string $logFile;
    private ?SettingRepository $settingRepo = null;

    public function __construct(?SettingRepository $settingRepo = null, ?string $logFile = null)
    {
        $this->settingRepo = $settingRepo;

        // Use provided path, or get from settings, or use default
        if ($logFile !== null) {
            $this->logFile = $logFile;
        } elseif ($settingRepo !== null) {
            $setting = $settingRepo->findByKey('logs.operations_path');
            $this->logFile = $setting ? $setting->value : '/var/log/phpborg_operations.log';
        } else {
            $this->logFile = '/var/log/phpborg_operations.log';
        }
    }

    /**
     * Log a user operation with context
     *
     * @param string $level - INFO, WARNING, ERROR
     * @param string $operation - backup_create, docker_restore, etc.
     * @param string $message - Human-readable message
     * @param array<string, mixed> $context - Additional data (server, archive, etc.)
     */
    public function log(string $level, string $operation, string $message, array $context = []): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? json_encode($context, JSON_UNESCAPED_SLASHES) : '';

        $logLine = sprintf(
            "[%s] [%s] [%s] %s %s\n",
            $timestamp,
            strtoupper($level),
            strtoupper($operation),
            $message,
            $contextStr
        );

        // Ensure log directory exists
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        // Append to log file
        file_put_contents($this->logFile, $logLine, FILE_APPEND | LOCK_EX);
    }

    public function info(string $operation, string $message, array $context = []): void
    {
        $this->log('INFO', $operation, $message, $context);
    }

    public function warning(string $operation, string $message, array $context = []): void
    {
        $this->log('WARNING', $operation, $message, $context);
    }

    public function error(string $operation, string $message, array $context = []): void
    {
        $this->log('ERROR', $operation, $message, $context);
    }
}
