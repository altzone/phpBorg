<?php

declare(strict_types=1);

namespace PhpBorg\Logger;

/**
 * Logger interface following PSR-3 principles
 */
interface LoggerInterface
{
    /**
     * System is unusable
     */
    public function emergency(string $message, string $server = 'CORE', array $context = []): void;

    /**
     * Action must be taken immediately
     */
    public function alert(string $message, string $server = 'CORE', array $context = []): void;

    /**
     * Critical conditions
     */
    public function critical(string $message, string $server = 'CORE', array $context = []): void;

    /**
     * Runtime errors that do not require immediate action
     */
    public function error(string $message, string $server = 'CORE', array $context = []): void;

    /**
     * Exceptional occurrences that are not errors
     */
    public function warning(string $message, string $server = 'CORE', array $context = []): void;

    /**
     * Normal but significant events
     */
    public function notice(string $message, string $server = 'CORE', array $context = []): void;

    /**
     * Interesting events
     */
    public function info(string $message, string $server = 'CORE', array $context = []): void;

    /**
     * Detailed debug information
     */
    public function debug(string $message, string $server = 'CORE', array $context = []): void;

    /**
     * Logs with an arbitrary level
     */
    public function log(string $level, string $message, string $server = 'CORE', array $context = []): void;
}
