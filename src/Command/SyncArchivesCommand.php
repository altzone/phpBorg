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
 * Sync archives from Borg repositories to database
 *
 * This command recovers archives that exist in Borg but are missing from the database.
 * Useful when database insertion fails but backup succeeded in Borg.
 */
final class SyncArchivesCommand extends Command
{
    public function __construct(
        private readonly Application $app
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('sync-archives')
            ->setDescription('Synchronize archives from Borg repositories to database')
            ->addOption('server-id', 's', InputOption::VALUE_OPTIONAL, 'Server ID to sync (optional)')
            ->addOption('type', 't', InputOption::VALUE_OPTIONAL, 'Backup type to sync (optional)')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Sync all repositories')
            ->setHelp(
                'This command synchronizes archives from Borg repositories to the database.' . PHP_EOL .
                'It scans Borg repositories and imports any archives missing from the database.' . PHP_EOL . PHP_EOL .
                'Examples:' . PHP_EOL .
                '  phpborg sync-archives --all                  # Sync all repositories' . PHP_EOL .
                '  phpborg sync-archives --server-id=1          # Sync all types for server 1' . PHP_EOL .
                '  phpborg sync-archives --server-id=1 --type=backup  # Sync backup type for server 1'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $serverId = $input->getOption('server-id') ? (int)$input->getOption('server-id') : null;
        $type = $input->getOption('type');
        $all = $input->getOption('all');

        $io->title("phpBorg - Archive Synchronization");

        // Validation
        if (!$all && $serverId === null) {
            $io->error('You must specify either --all or --server-id');
            return Command::FAILURE;
        }

        if ($type !== null && $serverId === null) {
            $io->error('You must specify --server-id when using --type');
            return Command::FAILURE;
        }

        try {
            $backupService = $this->app->getBackupService();

            // Display sync scope
            if ($all) {
                $io->writeln('Syncing: <info>All repositories</info>');
            } elseif ($type !== null) {
                $io->writeln("Syncing: <info>Server #{$serverId}, Type: {$type}</info>");
            } else {
                $io->writeln("Syncing: <info>Server #{$serverId}, All types</info>");
            }
            $io->writeln('');

            $io->section('Scanning Borg repositories...');

            // Execute sync
            $result = $backupService->syncArchivesFromBorg($serverId, $type);

            $io->newLine();
            $io->section('Synchronization Results');

            // Display summary
            $io->success("Synchronization completed!");
            $io->writeln("  Archives imported: <info>{$result['synced']}</info>");
            $io->writeln("  Errors: <comment>{$result['errors']}</comment>");

            // Display details
            if (!empty($result['details'])) {
                $io->newLine();
                $io->section('Details');

                $tableRows = [];
                foreach ($result['details'] as $detail) {
                    $repository = $detail['repository'] ?? 'unknown';
                    $action = $detail['action'] ?? 'unknown';
                    $archive = $detail['archive'] ?? 'N/A';
                    $error = $detail['error'] ?? '';

                    $status = match($action) {
                        'imported' => '<info>✓ Imported</info>',
                        'error' => '<error>✗ Error</error>',
                        'repository_error' => '<error>✗ Repository Error</error>',
                        default => $action,
                    };

                    $tableRows[] = [
                        $repository,
                        $archive,
                        $status,
                        $error ? substr($error, 0, 80) . '...' : '',
                    ];
                }

                $io->table(
                    ['Repository', 'Archive', 'Status', 'Error'],
                    $tableRows
                );
            }

            if ($result['errors'] > 0) {
                $io->warning("Some archives failed to sync. Check the logs for details.");
                return Command::FAILURE;
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error("Sync failed: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }
}
