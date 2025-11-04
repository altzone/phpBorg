<?php

declare(strict_types=1);

namespace PhpBorg\Command;

use PhpBorg\Application;
use PhpBorg\Repository\DatabaseInfoRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Full backup command - backup all active servers
 */
final class FullBackupCommand extends Command
{
    public function __construct(
        private readonly Application $app
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('backup:full')
            ->setDescription('Execute backup for all active servers')
            ->setHelp('This command runs backup for all active servers and their databases');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('phpBorg - Full Backup');

        try {
            $serverRepo = $this->app->getServerRepository();
            $dbInfoRepo = $this->app->getDatabaseInfoRepository();
            $backupService = $this->app->getBackupService();

            $servers = $serverRepo->findAllActive();

            if (empty($servers)) {
                $io->warning('No active servers found');
                return Command::SUCCESS;
            }

            $io->writeln(sprintf('Found %d active server(s)', count($servers)));
            $io->writeln('');

            $progressBar = new ProgressBar($output);
            $progressBar->setFormat('verbose');

            $results = [];
            $successCount = 0;
            $failureCount = 0;

            foreach ($servers as $server) {
                $io->section("Backing up: {$server->name}");

                // Filesystem backup
                $progressBar->setMessage("Filesystem backup...");
                $progressBar->display();

                $result = $backupService->executeBackup($server, 'backup');
                $results[] = [
                    'server' => $server->name,
                    'type' => 'filesystem',
                    'success' => $result['success'],
                    'error' => $result['error'] ?? null,
                ];

                if ($result['success']) {
                    $successCount++;
                    $io->writeln(" ✓ Filesystem backup completed");
                } else {
                    $failureCount++;
                    $io->writeln(" ✗ Filesystem backup failed: " . ($result['error'] ?? 'Unknown error'));
                }

                // Database backups
                $dbConfigs = $dbInfoRepo->findByServerId($server->id);

                foreach ($dbConfigs as $dbConfig) {
                    $progressBar->setMessage("{$dbConfig->type} backup...");
                    $progressBar->display();

                    $result = $backupService->executeBackup($server, $dbConfig->type);
                    $results[] = [
                        'server' => $server->name,
                        'type' => $dbConfig->type,
                        'success' => $result['success'],
                        'error' => $result['error'] ?? null,
                    ];

                    if ($result['success']) {
                        $successCount++;
                        $io->writeln(" ✓ {$dbConfig->type} backup completed");
                    } else {
                        $failureCount++;
                        $io->writeln(" ✗ {$dbConfig->type} backup failed: " . ($result['error'] ?? 'Unknown error'));
                    }
                }

                $io->writeln('');
            }

            $progressBar->finish();
            $io->writeln('');
            $io->writeln('');

            // Summary
            $io->section('Backup Summary');
            $io->table(
                ['Server', 'Type', 'Status', 'Error'],
                array_map(fn($r) => [
                    $r['server'],
                    $r['type'],
                    $r['success'] ? '✓ Success' : '✗ Failed',
                    $r['error'] ?? '-',
                ], $results)
            );

            $io->writeln('');
            $io->writeln(sprintf('Total: %d successful, %d failed', $successCount, $failureCount));

            return $failureCount > 0 ? Command::FAILURE : Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error("Error: {$e->getMessage()}");
            if ($this->app->getConfig()->isDebug()) {
                $io->writeln($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }
}
