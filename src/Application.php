<?php

declare(strict_types=1);

namespace PhpBorg;

use PhpBorg\Config\Configuration;
use PhpBorg\Database\Connection;
use PhpBorg\Logger\FileLogger;
use PhpBorg\Logger\LoggerInterface;
use PhpBorg\Repository\ArchiveRepository;
use PhpBorg\Repository\BorgRepositoryRepository;
use PhpBorg\Repository\DatabaseInfoRepository;
use PhpBorg\Repository\ReportRepository;
use PhpBorg\Repository\ServerRepository;
use PhpBorg\Service\Backup\BackupService;
use PhpBorg\Service\Backup\BorgExecutor;
use PhpBorg\Service\Backup\MountService;
use PhpBorg\Service\Database\ElasticsearchBackupStrategy;
use PhpBorg\Service\Database\LvmSnapshotManager;
use PhpBorg\Service\Database\MongoDbBackupStrategy;
use PhpBorg\Service\Database\MysqlBackupStrategy;
use PhpBorg\Service\Database\PostgresBackupStrategy;
use PhpBorg\Service\Repository\EncryptionService;
use PhpBorg\Service\Server\ServerManager;
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

    // Services

    public function getSshExecutor(): SshExecutor
    {
        return $this->getService(SshExecutor::class, fn() =>
            new SshExecutor($this->logger)
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
                $this->logger
            );

            // Register database backup strategies
            $lvmManager = $this->getLvmSnapshotManager();

            $service->registerDatabaseStrategy(
                new MysqlBackupStrategy($lvmManager, $this->logger)
            );

            $service->registerDatabaseStrategy(
                new PostgresBackupStrategy(
                    $lvmManager,
                    $this->getSshExecutor(),
                    $this->logger
                )
            );

            $service->registerDatabaseStrategy(
                new ElasticsearchBackupStrategy(
                    $this->getSshExecutor(),
                    $this->logger
                )
            );

            $service->registerDatabaseStrategy(
                new MongoDbBackupStrategy(
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

    /**
     * Cleanup resources
     */
    public function shutdown(): void
    {
        $this->connection->close();
    }
}
