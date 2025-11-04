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
 * List command - List archives or repository contents
 */
final class ListCommand extends Command
{
    public function __construct(
        private readonly Application $app
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('list')
            ->setDescription('List backup archives for a server')
            ->addArgument('server', InputArgument::REQUIRED, 'Server name')
            ->addOption('type', 't', InputOption::VALUE_OPTIONAL, 'Backup type (backup, mysql, postgres, etc.)', 'backup')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Limit number of results', 50)
            ->setHelp('This command lists all available backup archives for a server');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $serverName = $input->getArgument('server');
        $type = $input->getOption('type');
        $limit = (int)$input->getOption('limit');

        $io->title("phpBorg - Backup Archives List");
        $io->writeln("Server: <info>{$serverName}</info>");
        $io->writeln("Type: <info>{$type}</info>");
        $io->writeln('');

        try {
            $serverRepo = $this->app->getServerRepository();
            $repositoryRepo = $this->app->getBorgRepositoryRepository();
            $archiveRepo = $this->app->getArchiveRepository();

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
                $io->note("Use 'phpborg server:add {$serverName}' to add this server");
                return Command::FAILURE;
            }

            // Get archives
            $archives = $archiveRepo->findByRepositoryId($repository->repoId);

            if (empty($archives)) {
                $io->warning('No archives found for this server');
                $io->note("Create a backup with: phpborg backup {$serverName} --type={$type}");
                return Command::SUCCESS;
            }

            // Limit results
            $totalArchives = count($archives);
            if ($totalArchives > $limit) {
                $archives = array_slice($archives, 0, $limit);
                $io->note("Showing latest {$limit} archives out of {$totalArchives} (use --limit to change)");
            }

            // Display archives table
            $rows = [];
            foreach ($archives as $index => $archive) {
                $compressionRatio = $archive->getCompressionRatio();
                $deduplicationRatio = $archive->getDeduplicationRatio();

                $rows[] = [
                    $index,
                    $archive->name,
                    $archive->end->format('Y-m-d H:i:s'),
                    $archive->getFormattedDuration(),
                    $this->formatBytes($archive->originalSize),
                    $this->formatBytes($archive->compressedSize),
                    sprintf('%.1f%%', $compressionRatio),
                    $this->formatBytes($archive->deduplicatedSize),
                    sprintf('%.1f%%', $deduplicationRatio),
                    number_format($archive->filesCount),
                ];
            }

            $io->table(
                ['#', 'Archive Name', 'Date', 'Duration', 'Original', 'Compressed', 'Comp%', 'Deduplicated', 'Dedup%', 'Files'],
                $rows
            );

            $io->writeln('');
            $io->writeln("Total archives: <info>{$totalArchives}</info>");

            // Display repository stats
            $io->section('Repository Statistics');
            $io->table(
                ['Metric', 'Value'],
                [
                    ['Total Size', $this->formatBytes($repository->size)],
                    ['Compressed Size', $this->formatBytes($repository->compressedSize)],
                    ['Deduplicated Size', $this->formatBytes($repository->deduplicatedSize)],
                    ['Total Chunks', number_format($repository->totalChunks)],
                    ['Unique Chunks', number_format($repository->totalUniqueChunks)],
                    ['Compression', $repository->compression],
                    ['Encryption', $repository->encryption],
                    ['Retention (days)', $repository->retention],
                ]
            );

            $io->writeln('');
            $io->note([
                "To mount an archive: phpborg mount {$serverName} --type={$type}",
                "To get repository info: phpborg info {$serverName} --type={$type}",
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
