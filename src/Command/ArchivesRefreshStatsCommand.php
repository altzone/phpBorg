<?php

declare(strict_types=1);

namespace PhpBorg\Command;

use PhpBorg\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Refresh/backfill archive size stats from Borg (Bug 29).
 *
 * Imported archives are stored with 0 sizes (sync only fetches names/dates fast).
 * This re-runs `borg info` for EVERY archive already in the DB and updates
 * osize/csize/dsize/nfiles/dur. Fast when the borg chunk cache is hot (right after a
 * backup), slow when cold.
 */
final class ArchivesRefreshStatsCommand extends Command
{
    public function __construct(private readonly Application $app)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('archives:refresh-stats')
            ->setDescription('Refresh/backfill archive size stats (osize/csize/dsize/nfiles) from borg info')
            ->addOption('repo', 'r', InputOption::VALUE_OPTIONAL, 'Repository ID to refresh')
            ->addOption('server-id', 's', InputOption::VALUE_OPTIONAL, 'Server ID to refresh (all its repositories)')
            ->addOption('type', 't', InputOption::VALUE_OPTIONAL, 'Backup type (with --server-id)')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Refresh ALL repositories')
            ->setHelp(
                'Re-runs `borg info` for every archive already in the database and updates its sizes.' . PHP_EOL .
                'Examples:' . PHP_EOL .
                '  phpborg archives:refresh-stats --all' . PHP_EOL .
                '  phpborg archives:refresh-stats --repo 3' . PHP_EOL .
                '  phpborg archives:refresh-stats --server-id 1 --type backup' . PHP_EOL . PHP_EOL .
                'TIP: run this right after a backup while the borg chunk cache is HOT — it is fast then,' . PHP_EOL .
                'and can be very slow on a cold cache for a large repository.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('phpBorg - Refresh Archive Stats');

        $repoOpt = $input->getOption('repo');
        $serverId = $input->getOption('server-id') !== null ? (int)$input->getOption('server-id') : null;
        $type = $input->getOption('type');
        $all = (bool)$input->getOption('all');

        if (!$all && $repoOpt === null && $serverId === null) {
            $io->error('Specify --all, --repo ID, or --server-id ID');
            return Command::FAILURE;
        }

        // Resolve a --repo ID to its server_id + type.
        if ($repoOpt !== null) {
            $repository = $this->app->getBorgRepositoryRepository()->findById((int)$repoOpt);
            if ($repository === null) {
                $io->error("Repository #{$repoOpt} not found");
                return Command::FAILURE;
            }
            $serverId = $repository->serverId;
            $type = $repository->type;
        }

        $io->warning('This runs `borg info` per archive. It is fast on a HOT borg cache (just after a backup) but can be slow on a cold cache for large repositories.');

        try {
            $result = $this->app->getBackupService()->refreshArchiveStats($serverId, $type);

            $io->success('Stats refresh completed');
            $io->writeln("  Archives refreshed: <info>{$result['refreshed']}</info>");
            $io->writeln("  Errors: <comment>{$result['errors']}</comment>");

            if ($result['errors'] > 0) {
                $io->warning('Some archives failed — check the logs.');
                return Command::FAILURE;
            }
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error("Refresh failed: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }
}
