<?php

declare(strict_types=1);

namespace PhpBorg\Command;

use PhpBorg\Application;
use PhpBorg\Service\Queue\Handlers\BackupCreateHandler;
use PhpBorg\Service\Queue\Handlers\ServerSetupHandler;
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
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $queueName = $input->getOption('queue');

        $output->writeln('<info>Starting phpBorg worker...</info>');
        $output->writeln("Queue: {$queueName}");
        $output->writeln('');

        // Get services from DI container
        $jobQueue = $this->app->getJobQueue();
        $logger = $this->app->getLogger();

        // Create worker
        $worker = new Worker($jobQueue, $logger);

        // Register job handlers
        $worker->registerHandler('test_job', new TestJobHandler());

        $worker->registerHandler('server_setup', new ServerSetupHandler(
            $this->app->getServerManager(),
            $this->app->getBorgRepositoryRepository(),
            $this->app->getEncryptionService(),
            $this->app->config,
            $logger
        ));

        $worker->registerHandler('backup_create', new BackupCreateHandler(
            $this->app->getBackupService(),
            $this->app->getServerManager(),
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
