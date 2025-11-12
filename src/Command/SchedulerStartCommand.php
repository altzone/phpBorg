<?php

declare(strict_types=1);

namespace PhpBorg\Command;

use PhpBorg\Application;
use PhpBorg\Service\Queue\SchedulerWorker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Scheduler command - starts the scheduler worker
 */
final class SchedulerStartCommand extends Command
{
    public function __construct(
        private readonly Application $app
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('scheduler:start')
            ->setDescription('Start scheduler worker for backup schedules and periodic tasks');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Starting phpBorg scheduler...</info>');
        $output->writeln('');

        // Get services from DI container
        $jobQueue = $this->app->getJobQueue();
        $jobRepository = $this->app->getBackupJobRepository();
        $serverRepository = $this->app->getServerRepository();
        $storagePoolRepository = $this->app->getStoragePoolRepository();
        $logger = $this->app->getLogger();

        // Create scheduler worker
        $scheduler = new SchedulerWorker(
            $jobQueue,
            $jobRepository,
            $serverRepository,
            $storagePoolRepository,
            $logger
        );

        $output->writeln('<comment>Scheduler ready. Tasks:</comment>');
        $output->writeln('  - Check backup schedules every 60 seconds');
        $output->writeln('  - Collect server stats every 15 minutes');
        $output->writeln('  - Collect storage pool stats every 15 minutes');
        $output->writeln('');
        $output->writeln('<comment>Press Ctrl+C to stop</comment>');
        $output->writeln('');

        // Start scheduler (blocking)
        $scheduler->start();

        return Command::SUCCESS;
    }
}
