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

        // Bug 30: default under /var/log/phpborg/ — that dir is created and chowned to
        // phpborg by the installer and already covered by logrotate, so the phpborg user
        // can write it (the old /var/log/phpborg_operations.log lived directly in the
        // root-owned /var/log and could never be created by the worker → permission-denied
        // warning spam on every operation).
        $default = '/var/log/phpborg/operations.log';
        if ($logFile !== null) {
            $this->logFile = $logFile;
        } elseif ($settingRepo !== null) {
            $setting = $settingRepo->findByKey('logs.operations_path');
            $this->logFile = $setting ? $setting->value : $default;
        } else {
            $this->logFile = $default;
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
            @mkdir($logDir, 0775, true);
        }

        // Append to log file. Resilient (Bug 30): operation logging must never spam
        // warnings nor break a job. If the configured path is not writable, fall back
        // once to the app-local var/log (writable by phpborg) for the rest of the request.
        if (@file_put_contents($this->logFile, $logLine, FILE_APPEND | LOCK_EX) === false) {
            $fallback = dirname(__DIR__, 2) . '/var/log/operations.log';
            if ($fallback !== $this->logFile) {
                @mkdir(dirname($fallback), 0775, true);
                @file_put_contents($fallback, $logLine, FILE_APPEND | LOCK_EX);
                $this->logFile = $fallback;
            }
        }
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
