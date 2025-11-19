<?php

declare(strict_types=1);

namespace PhpBorg\Logger;

use PhpBorg\Repository\SettingRepository;

/**
 * Logger for Instant Recovery operations
 * Separate log file for easier monitoring and debugging
 */
final class InstantRecoveryLogger
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
            $setting = $settingRepo->findByKey('logs.instant_recovery_path');
            $this->logFile = $setting ? $setting->value : '/var/log/phpborg_instant_recovery.log';
        } else {
            $this->logFile = '/var/log/phpborg_instant_recovery.log';
        }
    }

    /**
     * Log an instant recovery operation with context
     *
     * @param string $level - INFO, WARNING, ERROR
     * @param string $operation - start, stop, mount, unmount, docker_start, docker_stop
     * @param string $message - Human-readable message
     * @param array<string, mixed> $context - Additional data (session, archive, etc.)
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

    public function debug(string $operation, string $message, array $context = []): void
    {
        $this->log('DEBUG', $operation, $message, $context);
    }
}
