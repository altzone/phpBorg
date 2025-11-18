<?php

declare(strict_types=1);

namespace PhpBorg;

use PhpBorg\Config\Configuration;
use PhpBorg\Database\Connection;
use PhpBorg\Logger\FileLogger;
use PhpBorg\Logger\LoggerInterface;
use PhpBorg\Repository\ArchiveRepository;
use PhpBorg\Repository\ArchiveMountRepository;
use PhpBorg\Repository\BackupJobRepository;
use PhpBorg\Repository\BorgRepositoryRepository;
use PhpBorg\Repository\DatabaseInfoRepository;
use PhpBorg\Repository\InstantRecoverySessionRepository;
use PhpBorg\Repository\JobRepository;
use PhpBorg\Repository\RefreshTokenRepository;
use PhpBorg\Repository\ReportRepository;
use PhpBorg\Repository\ServerRepository;
use PhpBorg\Repository\ServerStatsRepository;
use PhpBorg\Repository\SettingRepository;
use PhpBorg\Repository\StoragePoolRepository;
use PhpBorg\Repository\UserRepository;
use PhpBorg\Service\Auth\AuthService;
use PhpBorg\Service\Auth\JWTService;
use PhpBorg\Service\Backup\BackupService;
use PhpBorg\Service\Backup\BorgExecutor;
use PhpBorg\Service\Backup\MountService;
use PhpBorg\Service\Queue\JobQueue;
use PhpBorg\Service\Database\DockerBackupStrategy;
use PhpBorg\Service\Database\ElasticsearchBackupStrategy;
use PhpBorg\Service\Database\LvmSnapshotManager;
use PhpBorg\Service\Database\MongoDbBackupStrategy;
use PhpBorg\Service\Database\MysqlBackupStrategy;
use PhpBorg\Service\Database\PostgresBackupStrategy;
use PhpBorg\Service\InstantRecovery\InstantRecoveryManager;
use PhpBorg\Service\Repository\EncryptionService;
use PhpBorg\Service\Server\ServerManager;
use PhpBorg\Service\Server\ServerStatsCollector;
use PhpBorg\Service\Server\SshExecutor;
use PhpBorg\Service\Setup\SetupService;
use Symfony\Component\Dotenv\Dotenv;

/**
 * Main application container with dependency injection
 */
final class Application
{
    private Configuration $config;
    private Connection $connection;
    private LoggerInterface $logger;

    /** @var array<string, object> */
    private array $services = [];

    /**
     * @throws Exception\ConfigurationException
     */
    public function __construct(string $envPath = null)
    {
        $this->loadEnvironment($envPath);
        $this->config = Configuration::fromEnv();

        // Set timezone
        date_default_timezone_set($this->config->appTimezone);

        // Initialize core services
        $this->logger = new FileLogger(
            $this->config->logPath,
            $this->config->logLevel
        );

        $this->connection = new Connection(
            host: $this->config->dbHost,
            port: $this->config->dbPort,
            database: $this->config->dbName,
            username: $this->config->dbUser,
            password: $this->config->dbPassword,
            charset: $this->config->dbCharset
        );
    }

    /**
     * Load environment variables
     */
    private function loadEnvironment(?string $envPath): void
    {
        if ($envPath === null) {
            $envPath = dirname(__DIR__) . '/.env';
        }

        if (file_exists($envPath)) {
            $dotenv = new Dotenv();
            $dotenv->load($envPath);
        }
    }

    /**
     * Get configuration
     */
    public function getConfig(): Configuration
    {
        return $this->config;
    }

    /**
     * Get database connection
     */
    public function getConnection(): Connection
    {
        return $this->connection;
    }

    /**
     * Get logger
     */
    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    /**
     * Get or create service
     */
    private function getService(string $name, callable $factory): object
    {
        if (!isset($this->services[$name])) {
            $this->services[$name] = $factory();
        }
        return $this->services[$name];
    }

    // Repositories

    public function getServerRepository(): ServerRepository
    {
        return $this->getService(ServerRepository::class, fn() =>
            new ServerRepository($this->connection)
        );
    }

    public function getBorgRepositoryRepository(): BorgRepositoryRepository
    {
        return $this->getService(BorgRepositoryRepository::class, fn() =>
            new BorgRepositoryRepository($this->connection)
        );
    }

    public function getArchiveRepository(): ArchiveRepository
    {
        return $this->getService(ArchiveRepository::class, fn() =>
            new ArchiveRepository($this->connection)
        );
    }

    public function getArchiveMountRepository(): ArchiveMountRepository
    {
        return $this->getService(ArchiveMountRepository::class, fn() =>
            new ArchiveMountRepository($this->connection)
        );
    }

    public function getReportRepository(): ReportRepository
    {
        return $this->getService(ReportRepository::class, fn() =>
            new ReportRepository($this->connection)
        );
    }

    public function getDatabaseInfoRepository(): DatabaseInfoRepository
    {
        return $this->getService(DatabaseInfoRepository::class, fn() =>
            new DatabaseInfoRepository($this->connection)
        );
    }

    public function getUserRepository(): UserRepository
    {
        return $this->getService(UserRepository::class, fn() =>
            new UserRepository($this->connection)
        );
    }

    public function getRefreshTokenRepository(): RefreshTokenRepository
    {
        return $this->getService(RefreshTokenRepository::class, fn() =>
            new RefreshTokenRepository($this->connection)
        );
    }

    public function getJobRepository(): JobRepository
    {
        return $this->getService(JobRepository::class, fn() =>
            new JobRepository($this->connection)
        );
    }

    public function getSettingsRepository(): SettingRepository
    {
        return $this->getService(SettingRepository::class, fn() =>
            new SettingRepository($this->connection)
        );
    }

    public function getServerStatsRepository(): ServerStatsRepository
    {
        return $this->getService(ServerStatsRepository::class, fn() =>
            new ServerStatsRepository($this->connection)
        );
    }

    public function getSettingRepository(): SettingRepository
    {
        return $this->getService(SettingRepository::class, fn() =>
            new SettingRepository($this->connection)
        );
    }

    // Services

    public function getSshExecutor(): SshExecutor
    {
        return $this->getService(SshExecutor::class, fn() =>
            new SshExecutor($this->logger)
        );
    }

    public function getServerStatsCollector(): ServerStatsCollector
    {
        return $this->getService(ServerStatsCollector::class, fn() =>
            new ServerStatsCollector($this->getSshExecutor(), $this->logger)
        );
    }

    public function getBorgExecutor(): BorgExecutor
    {
        return $this->getService(BorgExecutor::class, fn() =>
            new BorgExecutor($this->config, $this->logger)
        );
    }

    public function getEncryptionService(): EncryptionService
    {
        return $this->getService(EncryptionService::class, fn() =>
            new EncryptionService($this->config)
        );
    }

    public function getLvmSnapshotManager(): LvmSnapshotManager
    {
        return $this->getService(LvmSnapshotManager::class, fn() =>
            new LvmSnapshotManager(
                $this->config,
                $this->getSshExecutor(),
                $this->logger
            )
        );
    }

    public function getBackupService(): BackupService
    {
        return $this->getService(BackupService::class, function() {
            $service = new BackupService(
                $this->config,
                $this->getBorgExecutor(),
                $this->getSshExecutor(),
                $this->getBorgRepositoryRepository(),
                $this->getArchiveRepository(),
                $this->getDatabaseInfoRepository(),
                $this->getReportRepository(),
                $this->getSettingsRepository(),
                $this->logger
            );

            // Register database backup strategies
            $lvmManager = $this->getLvmSnapshotManager();

            $service->registerDatabaseStrategy(
                new MysqlBackupStrategy($lvmManager, $this->logger, $this->getSshExecutor()),
                'mariadb' // Register under both 'mysql' and 'mariadb'
            );

            $service->registerDatabaseStrategy(
                new PostgresBackupStrategy(
                    $lvmManager,
                    $this->getSshExecutor(),
                    $this->logger
                ),
                'postgresql' // Register under both 'postgres' and 'postgresql'
            );

            $service->registerDatabaseStrategy(
                new ElasticsearchBackupStrategy(
                    $this->getSshExecutor(),
                    $this->logger
                )
            );

            $service->registerDatabaseStrategy(
                new MongoDbBackupStrategy(
                    $lvmManager,
                    $this->logger
                )
            );

            $service->registerDatabaseStrategy(
                new DockerBackupStrategy(
                    $this->getSshExecutor(),
                    $this->logger
                )
            );

            return $service;
        });
    }

    public function getMountService(): MountService
    {
        return $this->getService(MountService::class, fn() =>
            new MountService(
                $this->config,
                $this->getBorgExecutor(),
                $this->logger
            )
        );
    }

    public function getServerManager(): ServerManager
    {
        return $this->getService(ServerManager::class, fn() =>
            new ServerManager(
                $this->config,
                $this->getServerRepository(),
                $this->getBorgRepositoryRepository(),
                $this->getSshExecutor(),
                $this->getBorgExecutor(),
                $this->getEncryptionService(),
                $this->logger
            )
        );
    }

    public function getSetupService(): SetupService
    {
        return $this->getService(SetupService::class, fn() =>
            new SetupService($this->config, $this->logger)
        );
    }

    public function getJWTService(): JWTService
    {
        return $this->getService(JWTService::class, fn() =>
            new JWTService($this->config)
        );
    }

    public function getAuthService(): AuthService
    {
        return $this->getService(AuthService::class, fn() =>
            new AuthService(
                $this->getUserRepository(),
                $this->getRefreshTokenRepository(),
                $this->getJWTService(),
                $this->logger
            )
        );
    }

    public function getJobQueue(): JobQueue
    {
        return $this->getService(JobQueue::class, fn() =>
            new JobQueue(
                $this->config,
                $this->getJobRepository(),
                $this->logger
            )
        );
    }

    public function getBackupJobRepository(): BackupJobRepository
    {
        return $this->getService(BackupJobRepository::class, fn() =>
            new BackupJobRepository($this->connection)
        );
    }

    public function getStoragePoolRepository(): StoragePoolRepository
    {
        return $this->getService(StoragePoolRepository::class, fn() =>
            new StoragePoolRepository($this->connection)
        );
    }

    public function getEmailService(): \PhpBorg\Service\Email\EmailService
    {
        return $this->getService(\PhpBorg\Service\Email\EmailService::class, fn() =>
            new \PhpBorg\Service\Email\EmailService(
                $this->getSettingRepository(),
                $this->logger
            )
        );
    }

    public function getBackupNotificationService(): \PhpBorg\Service\Email\BackupNotificationService
    {
        return $this->getService(\PhpBorg\Service\Email\BackupNotificationService::class, fn() =>
            new \PhpBorg\Service\Email\BackupNotificationService(
                $this->getEmailService(),
                $this->getBackupJobRepository(),
                $this->getSettingRepository(),
                $this->logger
            )
        );
    }

    public function getInstantRecoverySessionRepository(): InstantRecoverySessionRepository
    {
        return $this->getService(InstantRecoverySessionRepository::class, fn() =>
            new InstantRecoverySessionRepository($this->connection)
        );
    }

    public function getInstantRecoveryManager(): InstantRecoveryManager
    {
        return $this->getService(InstantRecoveryManager::class, fn() =>
            new InstantRecoveryManager(
                $this->config,
                $this->getBorgExecutor(),
                $this->getSshExecutor(),
                $this->getInstantRecoverySessionRepository(),
                $this->getBorgRepositoryRepository(),
                $this->logger
            )
        );
    }

    public function getRestoreOperationRepository(): \PhpBorg\Repository\RestoreOperationRepository
    {
        return $this->getService(\PhpBorg\Repository\RestoreOperationRepository::class, fn() =>
            new \PhpBorg\Repository\RestoreOperationRepository($this->connection)
        );
    }

    public function getDockerRestoreService(): \PhpBorg\Service\Docker\DockerRestoreService
    {
        return $this->getService(\PhpBorg\Service\Docker\DockerRestoreService::class, fn() =>
            new \PhpBorg\Service\Docker\DockerRestoreService(
                $this->getRestoreOperationRepository(),
                $this->getArchiveRepository(),
                $this->getBorgRepositoryRepository(),
                $this->getServerRepository(),
                $this->getSshExecutor(),
                $this->getBorgExecutor(),
                $this->logger
            )
        );
    }

    /**
     * Cleanup resources
     */
    public function shutdown(): void
    {
        $this->connection->close();
    }
}
