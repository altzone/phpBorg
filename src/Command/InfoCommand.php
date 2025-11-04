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
 * Info command - Display repository information
 */
final class InfoCommand extends Command
{
    public function __construct(
        private readonly Application $app
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('info')
            ->setDescription('Display repository information')
            ->addArgument('server', InputArgument::REQUIRED, 'Server name')
            ->addOption('type', 't', InputOption::VALUE_OPTIONAL, 'Backup type (backup, mysql, postgres, etc.)', 'backup')
            ->addOption('json', 'j', InputOption::VALUE_NONE, 'Output as JSON')
            ->setHelp('This command displays detailed information about a backup repository');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $serverName = $input->getArgument('server');
        $type = $input->getOption('type');
        $jsonOutput = $input->getOption('json');

        try {
            $serverRepo = $this->app->getServerRepository();
            $repositoryRepo = $this->app->getBorgRepositoryRepository();
            $archiveRepo = $this->app->getArchiveRepository();
            $borgExecutor = $this->app->getBorgExecutor();

            // Get server
            $server = $serverRepo->findByName($serverName);
            if ($server === null) {
                $io->error("Server '{$serverName}' not found");
                return Command::FAILURE;
            }

            // Get repository
            $repository = $repositoryRepo->findByServerAndType($server->id, $type);
            if ($repository === null) {
                $io->error("No {$type} repository found for server '{$serverName}'");
                return Command::FAILURE;
            }

            // Get live info from Borg
            $this->app->getLogger()->info("Fetching repository info", $serverName);
            $borgInfo = $borgExecutor->getRepositoryInfo($repository->repoPath, $repository->passphrase);

            // Get archives count
            $archivesCount = $archiveRepo->countByRepositoryId($repository->repoId);

            if ($jsonOutput) {
                // JSON output
                $output->writeln(json_encode($borgInfo, JSON_PRETTY_PRINT));
                return Command::SUCCESS;
            }

            // Human-readable output
            $io->title("phpBorg - Repository Information");

            $io->section('Server Information');
            $io->table(
                ['Property', 'Value'],
                [
                    ['Server Name', $server->name],
                    ['Server Host', $server->host],
                    ['Server Port', $server->port],
                    ['Backup Type', $type],
                    ['Active', $server->active ? '✓ Yes' : '✗ No'],
                ]
            );

            $io->section('Repository Configuration');
            $io->table(
                ['Property', 'Value'],
                [
                    ['Repository ID', $repository->repoId],
                    ['Repository Path', $repository->repoPath],
                    ['Encryption', $repository->encryption],
                    ['Compression', $repository->compression],
                    ['Rate Limit', $repository->rateLimit > 0 ? $repository->rateLimit . ' KB/s' : 'Unlimited'],
                    ['Retention (days)', $repository->retention],
                    ['Last Modified', $repository->modified->format('Y-m-d H:i:s')],
                ]
            );

            $io->section('Backup Paths');
            foreach ($repository->getBackupPaths() as $path) {
                $io->writeln("  • {$path}");
            }

            if ($repository->exclude) {
                $io->section('Exclusion Patterns');
                foreach ($repository->getExclusionPatterns() as $pattern) {
                    $io->writeln("  • {$pattern}");
                }
            }

            $io->section('Storage Statistics');

            $cacheStats = $borgInfo['cache']['stats'] ?? [];
            $totalSize = (int)($cacheStats['total_size'] ?? $repository->size);
            $uniqueSize = (int)($cacheStats['unique_csize'] ?? $repository->deduplicatedSize);
            $totalCsize = (int)($cacheStats['total_csize'] ?? $repository->compressedSize);

            $deduplicationRatio = $totalSize > 0 ? (1 - ($uniqueSize / $totalSize)) * 100 : 0;
            $compressionRatio = $totalSize > 0 ? (1 - ($totalCsize / $totalSize)) * 100 : 0;

            $io->table(
                ['Metric', 'Value'],
                [
                    ['Total Original Size', $this->formatBytes($totalSize)],
                    ['Total Compressed Size', $this->formatBytes($totalCsize)],
                    ['Compression Ratio', sprintf('%.2f%%', $compressionRatio)],
                    ['Unique Deduplicated Size', $this->formatBytes($uniqueSize)],
                    ['Deduplication Ratio', sprintf('%.2f%%', $deduplicationRatio)],
                    ['Total Chunks', number_format((int)($cacheStats['total_chunks'] ?? $repository->totalChunks))],
                    ['Unique Chunks', number_format((int)($cacheStats['total_unique_chunks'] ?? $repository->totalUniqueChunks))],
                ]
            );

            $io->section('Archives');
            $io->writeln("Total archives: <info>{$archivesCount}</info>");

            if ($archivesCount > 0) {
                $archives = $archiveRepo->findByRepositoryId($repository->repoId);
                $latestArchives = array_slice($archives, 0, 5);

                $io->writeln('');
                $io->writeln('Latest 5 archives:');
                foreach ($latestArchives as $archive) {
                    $io->writeln(sprintf(
                        "  • %s (%s) - %s, %s files",
                        $archive->name,
                        $archive->end->format('Y-m-d H:i:s'),
                        $this->formatBytes($archive->originalSize),
                        number_format($archive->filesCount)
                    ));
                }
            }

            $io->writeln('');
            $io->note([
                "To list all archives: phpborg list {$serverName} --type={$type}",
                "To mount an archive: phpborg mount {$serverName} --type={$type}",
                "To create a backup: phpborg backup {$serverName} --type={$type}",
            ]);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error("Error: {$e->getMessage()}");
            if ($this->app->getConfig()->isDebug()) {
                $io->writeln($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }

    /**
     * Format bytes to human readable
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
