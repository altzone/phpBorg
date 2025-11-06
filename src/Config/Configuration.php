<?php

declare(strict_types=1);

namespace PhpBorg\Config;

use PhpBorg\Exception\ConfigurationException;

/**
 * Application configuration management
 * Reads configuration from environment variables
 */
final readonly class Configuration
{
    public function __construct(
        public string $dbHost,
        public int $dbPort,
        public string $dbName,
        public string $dbUser,
        public string $dbPassword,
        public string $dbCharset,
        public string $borgBinaryPath,
        public string $borgBackupPath,
        public string $borgArchiveDir,
        public string $borgLvmSnapName,
        public string $borgServerIpPublic,
        public string $borgServerIpPrivate,
        public string $logPath,
        public string $logLevel,
        public string $logChannel,
        public string $appEnv,
        public bool $appDebug,
        public string $appTimezone,
        public string $appSecret,
        public string $redisHost,
        public int $redisPort,
        public ?string $redisPassword,
        public int $redisDatabase,
    ) {
    }

    /**
     * Create configuration from environment variables
     *
     * @throws ConfigurationException
     */
    public static function fromEnv(): self
    {
        $required = [
            'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD',
            'BORG_BINARY_PATH', 'BORG_BACKUP_PATH', 'APP_SECRET'
        ];

        foreach ($required as $key) {
            if (empty($_ENV[$key])) {
                throw new ConfigurationException("Required environment variable {$key} is not set");
            }
        }

        return new self(
            dbHost: $_ENV['DB_HOST'],
            dbPort: (int)($_ENV['DB_PORT'] ?? 3306),
            dbName: $_ENV['DB_NAME'],
            dbUser: $_ENV['DB_USER'],
            dbPassword: $_ENV['DB_PASSWORD'],
            dbCharset: $_ENV['DB_CHARSET'] ?? 'utf8mb4',
            borgBinaryPath: $_ENV['BORG_BINARY_PATH'],
            borgBackupPath: $_ENV['BORG_BACKUP_PATH'],
            borgArchiveDir: $_ENV['BORG_ARCHIVE_DIR'] ?? 'backup',
            borgLvmSnapName: $_ENV['BORG_LVM_SNAP_NAME'] ?? 'phpborg',
            borgServerIpPublic: $_ENV['BORG_SERVER_IP_PUBLIC'] ?? '',
            borgServerIpPrivate: $_ENV['BORG_SERVER_IP_PRIVATE'] ?? '',
            logPath: $_ENV['LOG_PATH'] ?? '/var/log/phpborg.log',
            logLevel: $_ENV['LOG_LEVEL'] ?? 'info',
            logChannel: $_ENV['LOG_CHANNEL'] ?? 'phpborg',
            appEnv: $_ENV['APP_ENV'] ?? 'production',
            appDebug: filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
            appTimezone: $_ENV['APP_TIMEZONE'] ?? 'UTC',
            appSecret: $_ENV['APP_SECRET'],
            redisHost: $_ENV['REDIS_HOST'] ?? '127.0.0.1',
            redisPort: (int)($_ENV['REDIS_PORT'] ?? 6379),
            redisPassword: $_ENV['REDIS_PASSWORD'] ?? null,
            redisDatabase: (int)($_ENV['REDIS_DATABASE'] ?? 0),
        );
    }

    /**
     * Check if running in debug mode
     */
    public function isDebug(): bool
    {
        return $this->appDebug;
    }

    /**
     * Check if running in production
     */
    public function isProduction(): bool
    {
        return $this->appEnv === 'production';
    }
}
