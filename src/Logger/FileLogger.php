<?php

declare(strict_types=1);

namespace PhpBorg\Logger;

use PhpBorg\Exception\PhpBorgException;
use RuntimeException;

/**
 * File-based logger implementation with PSR-3 compliance
 */
final class FileLogger implements LoggerInterface
{
    private const LOG_LEVELS = [
        'emergency' => 0,
        'alert' => 1,
        'critical' => 2,
        'error' => 3,
        'warning' => 4,
        'notice' => 5,
        'info' => 6,
        'debug' => 7,
    ];

    private mixed $fileHandle = null;
    private readonly int $minLevel;

    public function __construct(
        private readonly string $logPath,
        string $minLevel = 'info',
        private readonly string $dateFormat = 'Y-m-d H:i:s.v',
    ) {
        if (!isset(self::LOG_LEVELS[$minLevel])) {
            throw new RuntimeException("Invalid log level: {$minLevel}");
        }
        $this->minLevel = self::LOG_LEVELS[$minLevel];
        $this->ensureLogFileExists();
    }

    public function emergency(string $message, string $server = 'CORE', array $context = []): void
    {
        $this->log('emergency', $message, $server, $context);
    }

    public function alert(string $message, string $server = 'CORE', array $context = []): void
    {
        $this->log('alert', $message, $server, $context);
    }

    public function critical(string $message, string $server = 'CORE', array $context = []): void
    {
        $this->log('critical', $message, $server, $context);
    }

    public function error(string $message, string $server = 'CORE', array $context = []): void
    {
        $this->log('error', $message, $server, $context);
    }

    public function warning(string $message, string $server = 'CORE', array $context = []): void
    {
        $this->log('warning', $message, $server, $context);
    }

    public function notice(string $message, string $server = 'CORE', array $context = []): void
    {
        $this->log('notice', $message, $server, $context);
    }

    public function info(string $message, string $server = 'CORE', array $context = []): void
    {
        $this->log('info', $message, $server, $context);
    }

    public function debug(string $message, string $server = 'CORE', array $context = []): void
    {
        $this->log('debug', $message, $server, $context);
    }

    public function log(string $level, string $message, string $server = 'CORE', array $context = []): void
    {
        $level = strtolower($level);

        if (!isset(self::LOG_LEVELS[$level])) {
            throw new RuntimeException("Invalid log level: {$level}");
        }

        // Check if we should log this level
        if (self::LOG_LEVELS[$level] > $this->minLevel) {
            return;
        }

        $timestamp = date($this->dateFormat);
        $levelUpper = strtoupper($level);
        $serverUpper = strtoupper($server);

        // Interpolate context values into message
        $message = $this->interpolate($message, $context);

        $contextString = !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES) : '';
        $logLine = "[{$timestamp}] [{$levelUpper}] [{$serverUpper}] {$message}{$contextString}" . PHP_EOL;

        $this->writeToFile($logLine);
    }

    /**
     * Interpolate context values into message placeholders
     */
    private function interpolate(string $message, array $context): string
    {
        $replace = [];
        foreach ($context as $key => $val) {
            if (is_scalar($val) || (is_object($val) && method_exists($val, '__toString'))) {
                $replace['{' . $key . '}'] = $val;
            }
        }
        return strtr($message, $replace);
    }

    /**
     * Write log line to file
     */
    private function writeToFile(string $logLine): void
    {
        if (!is_resource($this->fileHandle)) {
            $this->openLogFile();
        }

        if (fwrite($this->fileHandle, $logLine) === false) {
            throw new RuntimeException("Failed to write to log file: {$this->logPath}");
        }
    }

    /**
     * Ensure log file exists and is writable
     */
    private function ensureLogFileExists(): void
    {
        if (!file_exists($this->logPath)) {
            $directory = dirname($this->logPath);
            if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new RuntimeException("Failed to create log directory: {$directory}");
            }

            if (touch($this->logPath) === false) {
                throw new RuntimeException("Failed to create log file: {$this->logPath}");
            }
            chmod($this->logPath, 0644);
        }

        if (!is_writable($this->logPath)) {
            throw new RuntimeException("Log file is not writable: {$this->logPath}");
        }
    }

    /**
     * Open log file for appending
     */
    private function openLogFile(): void
    {
        $this->fileHandle = fopen($this->logPath, 'a');
        if ($this->fileHandle === false) {
            throw new RuntimeException("Failed to open log file: {$this->logPath}");
        }
    }

    /**
     * Close log file on destruction
     */
    public function __destruct()
    {
        if (is_resource($this->fileHandle)) {
            fclose($this->fileHandle);
        }
    }
}
