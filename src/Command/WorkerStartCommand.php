<?php

declare(strict_types=1);

namespace PhpBorg\Command;

use PhpBorg\Application;
use PhpBorg\Service\Queue\Handlers\ArchiveDeleteHandler;
use PhpBorg\Service\Queue\Handlers\ArchiveMountHandler;
use PhpBorg\Service\Queue\Handlers\ArchiveRestoreHandler;
use PhpBorg\Service\Queue\Handlers\BackupCreateHandler;
use PhpBorg\Service\Queue\Handlers\CapabilitiesDetectionHandler;
use PhpBorg\Service\Queue\Handlers\DockerConflictsDetectionHandler;
use PhpBorg\Service\Queue\Handlers\DockerRestoreHandler;
use PhpBorg\Service\Queue\Handlers\InstantRecoveryStartHandler;
use PhpBorg\Service\Queue\Handlers\InstantRecoveryStopHandler;
use PhpBorg\Service\Queue\Handlers\RepositoryDeleteHandler;
use PhpBorg\Service\Queue\Handlers\ServerSetupHandler;
use PhpBorg\Service\Queue\Handlers\ServerStatsCollectHandler;
use PhpBorg\Service\Queue\Handlers\StoragePoolAnalyzeHandler;
use PhpBorg\Service\Queue\Handlers\TestJobHandler;
use PhpBorg\Service\Queue\Worker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Worker command to process background jobs
 */
final class WorkerStartCommand extends Command
{
    public function __construct(
        private readonly Application $app
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('worker:start')
            ->setDescription('Start background worker to process jobs')
            ->addOption(
                'queue',
                null,
                InputOption::VALUE_OPTIONAL,
                'Queue name to process',
                'default'
            )
            ->addOption(
                'worker-id',
                null,
                InputOption::VALUE_OPTIONAL,
                'Worker ID (for logging)',
                null
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $queueName = $input->getOption('queue');
        $workerId = $input->getOption('worker-id');

        $output->writeln('<info>Starting phpBorg worker...</info>');
        $output->writeln("Queue: {$queueName}");
        if ($workerId) {
            $output->writeln("Worker ID: {$workerId}");
        }
        $output->writeln('');

        // Get services from DI container
        $jobQueue = $this->app->getJobQueue();
        $logger = $this->app->getLogger();

        // Create worker
        $worker = new Worker($jobQueue, $logger, $workerId);

        // Register job handlers
        $worker->registerHandler('test_job', new TestJobHandler());

        $worker->registerHandler('server_setup', new ServerSetupHandler(
            $this->app->getServerManager(),
            $this->app->getServerRepository(),
            $this->app->getConfig(),
            $logger
        ));

        $worker->registerHandler('backup_create', new BackupCreateHandler(
            $this->app->getBackupService(),
            $this->app->getServerManager(),
            $this->app->getBorgRepositoryRepository(),
            $this->app->getEncryptionService(),
            $this->app->getConfig(),
            $logger,
            $this->app->getBackupNotificationService()
        ));

        $worker->registerHandler('capabilities_detection', new CapabilitiesDetectionHandler(
            $this->app->getServerRepository(),
            $this->app->getSshExecutor(),
            $logger
        ));

        $worker->registerHandler('archive_delete', new ArchiveDeleteHandler(
            $this->app->getBorgExecutor(),
            $this->app->getArchiveRepository(),
            $this->app->getBorgRepositoryRepository(),
            $logger
        ));

        $worker->registerHandler('archive_mount', new ArchiveMountHandler(
            $this->app->getBorgExecutor(),
            $this->app->getArchiveRepository(),
            $this->app->getArchiveMountRepository(),
            $this->app->getBorgRepositoryRepository(),
            $logger
        ));

        $worker->registerHandler('archive_restore', new ArchiveRestoreHandler(
            $this->app->getArchiveRepository(),
            $this->app->getBorgRepositoryRepository(),
            $this->app->getServerRepository(),
            $this->app->getSettingRepository(),
            $this->app->getSshExecutor(),
            $logger
        ));

        $worker->registerHandler('server_stats_collect', new ServerStatsCollectHandler(
            $this->app->getServerRepository(),
            $this->app->getServerStatsRepository(),
            $this->app->getServerStatsCollector(),
            $logger
        ));

        $worker->registerHandler('storage_pool_analyze', new StoragePoolAnalyzeHandler(
            $this->app->getStoragePoolRepository(),
            $logger
        ));

        $worker->registerHandler('repository_delete', new RepositoryDeleteHandler(
            $this->app->getBorgRepositoryRepository(),
            $this->app->getArchiveRepository(),
            $this->app->getArchiveMountRepository(),
            $this->app->getBackupJobRepository(),
            $logger
        ));

        $worker->registerHandler('instant_recovery_start', new InstantRecoveryStartHandler($this->app));

        $worker->registerHandler('instant_recovery_stop', new InstantRecoveryStopHandler($this->app));

        $worker->registerHandler('docker_restore', new DockerRestoreHandler(
            $this->app->getRestoreOperationRepository(),
            $this->app->getArchiveRepository(),
            $this->app->getBorgRepositoryRepository(),
            $this->app->getServerRepository(),
            $this->app->getSshExecutor(),
            $this->app->getBorgExecutor(),
            $this->app->getDockerRestoreService(),
            $logger
        ));

        $worker->registerHandler('docker_conflicts_detection', new DockerConflictsDetectionHandler(
            $this->app->getDockerRestoreService(),
            $logger
        ));

        $output->writeln('<comment>Worker ready. Waiting for jobs...</comment>');
        $output->writeln('<comment>Press Ctrl+C to stop</comment>');
        $output->writeln('');

        // Start worker (blocking)
        $worker->start($queueName);

        return Command::SUCCESS;
    }
}
