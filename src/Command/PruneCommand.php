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
 * Prune command - Remove old archives according to retention policy
 */
final class PruneCommand extends Command
{
    public function __construct(
        private readonly Application $app
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('prune')
            ->setDescription('Prune old archives according to retention policy')
            ->addArgument('server', InputArgument::REQUIRED, 'Server name or "all" for all servers')
            ->addOption('type', 't', InputOption::VALUE_OPTIONAL, 'Backup type (backup, mysql, postgres, etc.)', null)
            ->setHelp('This command removes old backup archives according to the configured retention policy');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $serverName = $input->getArgument('server');
        $type = $input->getOption('type');

        $io->title('phpBorg - Prune Old Archives');

        try {
            $serverRepo = $this->app->getServerRepository();
            $repositoryRepo = $this->app->getBorgRepositoryRepository();
            $backupService = $this->app->getBackupService();

            if ($serverName === 'all') {
                return $this->pruneAllServers($io, $serverRepo, $repositoryRepo, $backupService);
            }

            // Single server
            $server = $serverRepo->findByName($serverName);
            if ($server === null) {
                $io->error("Server '{$serverName}' not found");
                return Command::FAILURE;
            }

            if ($type) {
                // Specific type
                return $this->pruneSingleRepository($io, $server, $type, $repositoryRepo, $backupService);
            }

            // All types for this server
            return $this->pruneServerRepositories($io, $server, $repositoryRepo, $backupService);

        } catch (\Exception $e) {
            $io->error("Error: {$e->getMessage()}");
            if ($this->app->getConfig()->isDebug()) {
                $io->writeln($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }

    /**
     * Prune all servers
     */
    private function pruneAllServers($io, $serverRepo, $repositoryRepo, $backupService): int
    {
        $io->info('Pruning ALL active servers...');
        $io->writeln('');

        $servers = $serverRepo->findAllActive();

        if (empty($servers)) {
            $io->warning('No active servers found');
            return Command::SUCCESS;
        }

        $totalPruned = 0;
        $errors = 0;

        foreach ($servers as $server) {
            $io->section("Server: {$server->name}");

            $repositories = $repositoryRepo->findByServerId($server->id);

            foreach ($repositories as $repository) {
                try {
                    $io->write("  Pruning {$repository->type} repository... ");

                    $beforeCount = $this->app->getArchiveRepository()->countByRepositoryId($repository->repoId);

                    $backupService->pruneArchives($repository, $server->name);

                    $afterCount = $this->app->getArchiveRepository()->countByRepositoryId($repository->repoId);
                    $prunedCount = $beforeCount - $afterCount;

                    if ($prunedCount > 0) {
                        $io->writeln("✓ Removed {$prunedCount} old archive(s)");
                        $totalPruned += $prunedCount;
                    } else {
                        $io->writeln("✓ No archives to prune");
                    }

                } catch (\Exception $e) {
                    $io->writeln("✗ Failed: {$e->getMessage()}");
                    $errors++;
                }
            }

            $io->writeln('');
        }

        $io->writeln('');
        $io->section('Summary');
        $io->writeln("Total archives pruned: <info>{$totalPruned}</info>");

        if ($errors > 0) {
            $io->warning("Encountered {$errors} error(s)");
            return Command::FAILURE;
        }

        $io->success('Prune completed successfully!');
        return Command::SUCCESS;
    }

    /**
     * Prune all repositories for a server
     */
    private function pruneServerRepositories($io, $server, $repositoryRepo, $backupService): int
    {
        $io->info("Pruning all repositories for server: {$server->name}");
        $io->writeln('');

        $repositories = $repositoryRepo->findByServerId($server->id);

        if (empty($repositories)) {
            $io->warning("No repositories found for server '{$server->name}'");
            return Command::SUCCESS;
        }

        $totalPruned = 0;
        $errors = 0;

        foreach ($repositories as $repository) {
            try {
                $io->write("Pruning {$repository->type} repository... ");

                $beforeCount = $this->app->getArchiveRepository()->countByRepositoryId($repository->repoId);

                $backupService->pruneArchives($repository, $server->name);

                $afterCount = $this->app->getArchiveRepository()->countByRepositoryId($repository->repoId);
                $prunedCount = $beforeCount - $afterCount;

                if ($prunedCount > 0) {
                    $io->writeln("✓ Removed {$prunedCount} old archive(s)");
                    $totalPruned += $prunedCount;
                } else {
                    $io->writeln("✓ No archives to prune");
                }

            } catch (\Exception $e) {
                $io->writeln("✗ Failed: {$e->getMessage()}");
                $errors++;
            }
        }

        $io->writeln('');
        $io->writeln("Total archives pruned: <info>{$totalPruned}</info>");

        if ($errors > 0) {
            $io->warning("Encountered {$errors} error(s)");
            return Command::FAILURE;
        }

        $io->success('Prune completed successfully!');
        return Command::SUCCESS;
    }

    /**
     * Prune a single repository
     */
    private function pruneSingleRepository($io, $server, $type, $repositoryRepo, $backupService): int
    {
        $io->info("Pruning {$type} repository for server: {$server->name}");
        $io->writeln('');

        $repository = $repositoryRepo->findByServerAndType($server->id, $type);

        if ($repository === null) {
            $io->error("No {$type} repository found for server '{$server->name}'");
            return Command::FAILURE;
        }

        try {
            $beforeCount = $this->app->getArchiveRepository()->countByRepositoryId($repository->repoId);

            $io->writeln("Current archives: <info>{$beforeCount}</info>");
            $io->writeln("Retention policy: <info>{$repository->retention} days</info>");
            $io->writeln('');

            $io->write('Pruning archives... ');

            $backupService->pruneArchives($repository, $server->name);

            $afterCount = $this->app->getArchiveRepository()->countByRepositoryId($repository->repoId);
            $prunedCount = $beforeCount - $afterCount;

            $io->writeln('✓ Done');
            $io->writeln('');

            if ($prunedCount > 0) {
                $io->success("Removed {$prunedCount} old archive(s). {$afterCount} archives remaining.");
            } else {
                $io->success('No archives to prune. All archives are within retention policy.');
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error("Failed to prune: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }
}
