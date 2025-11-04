<?php

declare(strict_types=1);

namespace PhpBorg\Command;

use PhpBorg\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Backup command
 */
final class BackupCommand extends Command
{
    public function __construct(
        private readonly Application $app
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('backup')
            ->setDescription('Execute backup for a server')
            ->addArgument('server', InputArgument::REQUIRED, 'Server name to backup')
            ->addOption('type', 't', InputOption::VALUE_OPTIONAL, 'Backup type (backup, mysql, postgres, elasticsearch, mongodb)', 'backup')
            ->setHelp('This command executes a backup for the specified server');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $serverName = $input->getArgument('server');
        $type = $input->getOption('type');

        $io->title("phpBorg Backup - {$type}");
        $io->info("Starting backup for server: {$serverName}");

        try {
            $serverRepo = $this->app->getServerRepository();
            $server = $serverRepo->findByName($serverName);

            if ($server === null) {
                $io->error("Server '{$serverName}' not found");
                return Command::FAILURE;
            }

            if (!$server->active) {
                $io->warning("Server '{$serverName}' is not active");
                return Command::FAILURE;
            }

            $backupService = $this->app->getBackupService();

            $result = $backupService->executeBackup($server, $type);

            if ($result['success']) {
                $io->success("Backup completed successfully!");
                $io->writeln("Archive: {$result['archiveName']}");
                $io->writeln("Report ID: {$result['reportId']}");
                return Command::SUCCESS;
            } else {
                $io->error("Backup failed: {$result['error']}");
                return Command::FAILURE;
            }
        } catch (\Exception $e) {
            $io->error("Error: {$e->getMessage()}");
            if ($this->app->getConfig()->isDebug()) {
                $io->writeln($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }
}
